<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\App;

/**
 * Periodic expiry of unpaid holds. Safe to run every tick: already-EXPIRED rows are skipped.
 */
final class BookingExpiryService
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{expired: int, bookings: list<array<string, mixed>>}
     */
    public function run(): array
    {
        $expired = $this->app->status()->expireOverdue();
        $out = [];
        foreach ($expired as $booking) {
            $emails = $this->notifyExpired($booking);
            $out[] = [
                'id' => (int) $booking['id'],
                'reference' => $booking['reference'],
                'status' => $booking['status'],
                'emails' => $emails,
            ];
        }
        return ['expired' => count($out), 'bookings' => $out];
    }

    /**
     * @param array<string, mixed> $booking
     * @return list<array<string, mixed>>
     */
    private function notifyExpired(array $booking): array
    {
        $vars = [
            'language' => $booking['language'] ?? 'nl',
            'reference' => $booking['reference'],
            'guest_name' => $booking['guest_name'],
            'guest_email' => $booking['guest_email'],
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
            'guests' => $booking['guests'],
            'deposit_cents' => $booking['deposit_cents'],
            'total_cents' => $booking['total_cents'],
            'remaining_cents' => $booking['remaining_cents'],
            'deposit_due_at' => $booking['payment_due_at'] ?? $booking['deposit_due_at'] ?? '',
            'reason' => 'Payment deadline passed',
        ];
        $id = (int) $booking['id'];
        $emails = [];
        $emails[] = $this->app->email()->sendTemplate(
            'booking_expired',
            (string) $booking['guest_email'],
            $vars,
            $id
        );
        $manager = $this->app->config->managerEmail;
        if ($manager !== '') {
            $emails[] = $this->app->email()->sendTemplate('manager_booking_expired', $manager, $vars, $id);
        }
        return $emails;
    }
}
