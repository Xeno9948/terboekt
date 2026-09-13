<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\App;
use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\ValidationException;

final class ManagerMailAction
{
    public const APPROVE = 'approve';
    public const REJECT = 'reject';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{approve_url: string, reject_url: string}
     */
    public function urlsFor(array $booking): array
    {
        return [
            'approve_url' => $this->url(self::APPROVE, $booking),
            'reject_url' => $this->url(self::REJECT, $booking),
        ];
    }

    /** @param array<string, mixed> $booking */
    public function url(string $action, array $booking): string
    {
        $exp = $this->expiryUnix($booking);
        $query = http_build_query([
            'ref' => (string) $booking['reference'],
            'action' => $action,
            'exp' => (string) $exp,
            'sig' => $this->signature($action, (string) $booking['reference'], (int) $booking['id'], $exp),
        ]);
        return rtrim($this->app->config->appBaseUrl, '/') . '/admin/mail-action.php?' . $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function bookingFromRequest(string $ref, string $action, string $expRaw, string $sig): array
    {
        if (!in_array($action, [self::APPROVE, self::REJECT], true)) {
            throw new ValidationException(['Onbekende actie.']);
        }
        $booking = $this->app->bookings()->findByIdOrReference($ref);
        if ($booking === null) {
            throw new ValidationException(['Deze reservatie bestaat niet meer.']);
        }
        $exp = (int) $expRaw;
        if ($exp < 1) {
            throw new ValidationException(['Deze link is ongeldig.']);
        }
        $expected = $this->signature($action, (string) $booking['reference'], (int) $booking['id'], $exp);
        if (!hash_equals($expected, $sig)) {
            throw new ValidationException(['Deze link is ongeldig of verlopen.']);
        }
        if ($exp < time()) {
            throw new ValidationException(['Deze link is verlopen. Open de boeking in het beheer.']);
        }
        return $booking;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    public function apply(array $booking, string $action): array
    {
        $actor = ['email' => $this->app->email()->managerEmail()];
        if ($action === self::REJECT) {
            if (in_array((string) $booking['status'], BookingStatus::TERMINAL, true)) {
                return $booking;
            }
            if ((string) $booking['status'] === BookingStatus::CONFIRMED) {
                throw new ValidationException(['Deze boeking is al bevestigd. Annuleer ze in het beheer als dat nodig is.']);
            }
            return $this->app->workflow()->reject((int) $booking['id'], $actor, [
                'reason' => 'Geweigerd via e-mail',
            ]);
        }

        $status = (string) $booking['status'];
        if ($status === BookingStatus::CONFIRMED) {
            return $booking;
        }
        if (!in_array($status, [BookingStatus::REQUESTED, BookingStatus::AWAITING_DEPOSIT], true)) {
            throw new ValidationException(['Deze aanvraag kan niet meer goedgekeurd worden.']);
        }
        return $this->app->workflow()->confirmDeposit((int) $booking['id'], $actor, [
            'reason' => 'Goedgekeurd via e-mail',
        ]);
    }

    /** @param array<string, mixed> $booking */
    private function expiryUnix(array $booking): int
    {
        $due = (string) ($booking['payment_due_at'] ?? $booking['deposit_due_at'] ?? '');
        if ($due !== '') {
            $fromDue = strtotime($due);
            if ($fromDue !== false) {
                return $fromDue + 2 * 86400;
            }
        }
        $created = strtotime((string) ($booking['created_at'] ?? 'now'));
        return ($created !== false ? $created : time()) + 21 * 86400;
    }

    private function signature(string $action, string $reference, int $id, int $exp): string
    {
        $payload = $action . '.' . $reference . '.' . $id . '.' . $exp;
        return hash_hmac('sha256', $payload, $this->secret());
    }

    private function secret(): string
    {
        foreach ([$this->app->config->icalExportSecret, $this->app->config->cronSecret] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return hash('sha256', $this->app->config->databaseUrl . '|manager-mail-action');
    }
}
