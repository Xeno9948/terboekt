<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\App;
use Terboekt\DateRange;
use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\NotFoundException;
use Terboekt\Domain\ValidationException;
use Terboekt\Money;

final class BookingWorkflowService
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Guest action: REQUESTED → AWAITING_DEPOSIT. Email must match; 404 on mismatch (no existence leak).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function confirmTransferIntent(int|string $idOrRef, array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? $input['guest_email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['valid email is required']);
        }
        $booking = $this->app->bookings()->findByIdOrReference($idOrRef);
        if ($booking === null || strtolower((string) $booking['guest_email']) !== $email) {
            throw new NotFoundException('Booking not found');
        }
        $updated = $this->app->status()->transition(
            (int) $booking['id'],
            BookingStatus::AWAITING_DEPOSIT,
            'guest',
            (string) $booking['guest_email'],
            'Guest confirmed bank-transfer intent'
        );
        $this->notifyQuietly($updated, BookingStatus::AWAITING_DEPOSIT);
        return $this->publicGuestView($updated);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function confirmDeposit(int|string $idOrRef, array $user, array $input = []): array
    {
        $reason = isset($input['reason']) ? (string) $input['reason'] : 'Deposit received';
        $updated = $this->app->status()->transition(
            $idOrRef,
            BookingStatus::CONFIRMED,
            'admin',
            (string) $user['email'],
            $reason,
            ['deposit_received_at' => $this->app->db->now()]
        );
        $this->notifyQuietly($updated, BookingStatus::CONFIRMED, $reason);
        return $updated;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reject(int|string $idOrRef, array $user, array $input = []): array
    {
        $reason = isset($input['reason']) ? (string) $input['reason'] : 'Rejected by manager';
        $updated = $this->app->status()->transition(
            $idOrRef,
            BookingStatus::REJECTED,
            'admin',
            (string) $user['email'],
            $reason,
            ['rejection_reason' => $reason]
        );
        $this->notifyQuietly($updated, BookingStatus::REJECTED, $reason);
        return $updated;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function cancel(int|string $idOrRef, array $user, array $input = []): array
    {
        $reason = isset($input['reason']) ? (string) $input['reason'] : 'Cancelled by manager';
        $updated = $this->app->status()->transition(
            $idOrRef,
            BookingStatus::CANCELLED,
            'admin',
            (string) $user['email'],
            $reason,
            ['cancellation_reason' => $reason]
        );
        $this->notifyQuietly($updated, BookingStatus::CANCELLED, $reason);
        return $updated;
    }

    /**
     * Release a pending hold (REQUESTED / AWAITING_DEPOSIT) and free the dates.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function releaseHold(int|string $idOrRef, array $user, array $input = []): array
    {
        $booking = $this->app->status()->load($idOrRef);
        $status = (string) $booking['status'];
        if (!in_array($status, [BookingStatus::REQUESTED, BookingStatus::AWAITING_DEPOSIT], true)) {
            throw new ValidationException(['Hold can only be released from REQUESTED or AWAITING_DEPOSIT']);
        }
        $reason = isset($input['reason']) ? (string) $input['reason'] : 'Hold released by manager';
        return $this->cancel($idOrRef, $user, ['reason' => $reason]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function extendDeadline(int|string $idOrRef, array $user, array $input = []): array
    {
        $booking = $this->app->status()->load($idOrRef);
        $status = (string) $booking['status'];
        if (!in_array($status, [BookingStatus::REQUESTED, BookingStatus::AWAITING_DEPOSIT], true)) {
            throw new ValidationException(['Deadline can only be extended for REQUESTED or AWAITING_DEPOSIT']);
        }

        $current = (string) ($booking['payment_due_at'] ?? $booking['deposit_due_at'] ?? $this->app->db->now());
        if (!empty($input['payment_due_at'])) {
            $due = (string) $input['payment_due_at'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $due)) {
                throw new ValidationException(['payment_due_at must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS']);
            }
            if (strlen($due) === 10) {
                $due .= ' 23:59:59';
            }
            $due = str_replace('T', ' ', $due);
        } else {
            $days = (int) ($input['days'] ?? $this->app->settings()->depositDeadlineDays());
            if ($days < 1 || $days > 60) {
                throw new ValidationException(['days must be between 1 and 60']);
            }
            $base = new \DateTimeImmutable($current, new \DateTimeZone('UTC'));
            $from = $base > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                ? $base
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $due = $from->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        }

        $updated = $this->app->db->transaction(function () use ($booking, $user, $due, $current) {
            $row = $this->app->bookings()->update((int) $booking['id'], [
                'payment_due_at' => $due,
                'deposit_due_at' => $due,
            ]);
            $this->app->audit()->record((int) $booking['id'], 'admin', (string) $user['email'], 'extend_deadline', [
                'from' => $current,
                'to' => $due,
            ]);
            $this->app->history()->record(
                (int) $booking['id'],
                (string) $booking['status'],
                (string) $booking['status'],
                'admin',
                (string) $user['email'],
                'Deadline extended to ' . $due
            );
            return $row;
        });
        return $updated;
    }

    /**
     * Admin patch: dates (revalidated), guest count, price adjust, internal notes. All audited.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function patch(int|string $idOrRef, array $user, array $input): array
    {
        $booking = $this->app->status()->load($idOrRef);
        if (in_array((string) $booking['status'], BookingStatus::TERMINAL, true)) {
            throw new ValidationException(['Terminal bookings cannot be patched']);
        }

        $checkIn = trim((string) ($input['checkin'] ?? $input['check_in'] ?? $booking['check_in']));
        $checkOut = trim((string) ($input['checkout'] ?? $input['check_out'] ?? $booking['check_out']));
        $guests = array_key_exists('guests', $input) ? (int) $input['guests'] : (int) $booking['guests'];
        $sunday = array_key_exists('sunday_evening', $input)
            ? !empty($input['sunday_evening'])
            : (int) $booking['sunday_evening_extra'] === 1;
        $datesChanged = $checkIn !== (string) $booking['check_in'] || $checkOut !== (string) $booking['check_out'];
        $guestsChanged = $guests !== (int) $booking['guests'];
        $sundayChanged = $sunday !== ((int) $booking['sunday_evening_extra'] === 1);

        $fields = [];
        $audit = [];

        if ($datesChanged) {
            DateRange::of($checkIn, $checkOut);
            $fields['check_in'] = $checkIn;
            $fields['check_out'] = $checkOut;
            $audit['check_in'] = ['from' => $booking['check_in'], 'to' => $checkIn];
            $audit['check_out'] = ['from' => $booking['check_out'], 'to' => $checkOut];
        }
        if ($guestsChanged) {
            $fields['guests'] = $guests;
            $audit['guests'] = ['from' => (int) $booking['guests'], 'to' => $guests];
        }
        if ($sundayChanged) {
            $fields['sunday_evening_extra'] = $sunday ? 1 : 0;
            $audit['sunday_evening_extra'] = ['from' => (int) $booking['sunday_evening_extra'], 'to' => $sunday ? 1 : 0];
        }
        if (array_key_exists('manager_notes', $input) || array_key_exists('internal_notes', $input)) {
            $notes = (string) ($input['manager_notes'] ?? $input['internal_notes'] ?? '');
            $fields['manager_notes'] = $notes === '' ? null : $notes;
            $audit['manager_notes'] = ['changed' => true];
        }

        $recalculate = $datesChanged || $guestsChanged || $sundayChanged
            || isset($input['extra_fees_cents'], $input['discount_cents'], $input['accommodation_cents'])
            || array_key_exists('extra_fees_cents', $input)
            || array_key_exists('discount_cents', $input)
            || array_key_exists('accommodation_cents', $input);

        $guestMoney = ['total_cents', 'deposit_cents', 'remaining_cents', 'total', 'deposit', 'price'];
        foreach ($guestMoney as $ignored) {
            unset($input[$ignored]);
        }

        if ($recalculate) {
            $quote = $this->app->pricing()->calculateBookingPrice($checkIn, $checkOut, $guests, $sunday);
            if (array_key_exists('accommodation_cents', $input)) {
                $quote['accommodation_cents'] = (int) $input['accommodation_cents'];
            }
            if (array_key_exists('extra_fees_cents', $input)) {
                $quote['extra_fees_cents'] = (int) $input['extra_fees_cents'];
            }
            if (array_key_exists('discount_cents', $input)) {
                $quote['discount_cents'] = (int) $input['discount_cents'];
            }
            $total = (int) $quote['accommodation_cents']
                + (int) $quote['cleaning_fee_cents']
                + (int) $quote['tourist_tax_cents']
                + (int) $quote['sunday_evening_cents']
                + (int) $quote['extra_fees_cents']
                - (int) $quote['discount_cents'];
            if ($total < 0) {
                $total = 0;
            }
            $deposit = Money::percent($total, $this->app->settings()->depositPercentage());
            $fields['nights'] = $quote['nights'];
            $fields['package_code'] = $quote['package'];
            $fields['season'] = $quote['season'];
            $fields['accommodation_cents'] = (int) $quote['accommodation_cents'];
            $fields['cleaning_fee_cents'] = (int) $quote['cleaning_fee_cents'];
            $fields['tourist_tax_cents'] = (int) $quote['tourist_tax_cents'];
            $fields['sunday_evening_cents'] = (int) $quote['sunday_evening_cents'];
            $fields['extra_fees_cents'] = (int) $quote['extra_fees_cents'];
            $fields['discount_cents'] = (int) $quote['discount_cents'];
            $fields['total_cents'] = $total;
            $fields['deposit_cents'] = $deposit;
            $fields['remaining_cents'] = $total - $deposit;
            $fields['security_deposit_cents'] = (int) $quote['security_deposit_cents'];
            $fields['pricing_snapshot'] = json_encode($quote + ['admin_adjusted' => true], JSON_UNESCAPED_UNICODE);
            $audit['pricing'] = [
                'total_cents' => $total,
                'deposit_cents' => $deposit,
            ];
        }

        if ($fields === []) {
            return $booking;
        }

        $updated = $this->app->db->transaction(function () use ($booking, $fields, $datesChanged, $checkIn, $checkOut, $user, $audit) {
            if ($datesChanged) {
                $this->app->availability()->moveHoldWithinTransaction((int) $booking['id'], $checkIn, $checkOut);
            }
            $row = $this->app->bookings()->update((int) $booking['id'], $fields);
            $this->app->audit()->record((int) $booking['id'], 'admin', (string) $user['email'], 'patch', $audit);
            $this->app->history()->record(
                (int) $booking['id'],
                (string) $booking['status'],
                (string) $booking['status'],
                'admin',
                (string) $user['email'],
                'Booking updated: ' . implode(', ', array_keys($audit))
            );
            return $row;
        });

        if ($datesChanged || $guestsChanged) {
            $this->notifyQuietly($updated, 'changed');
        }

        return $updated;
    }

    /**
     * Public guest payload: no manager notes, no other bookings, no extra PII.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    public function publicGuestView(array $booking): array
    {
        return [
            'reference' => $booking['reference'],
            'status' => $booking['status'],
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
            'nights' => (int) $booking['nights'],
            'guests' => (int) $booking['guests'],
            'total_cents' => (int) $booking['total_cents'],
            'deposit_cents' => (int) $booking['deposit_cents'],
            'remaining_cents' => (int) $booking['remaining_cents'],
            'security_deposit_cents' => (int) $booking['security_deposit_cents'],
            'currency' => $booking['currency'],
            'payment_due_at' => $booking['payment_due_at'] ?? $booking['deposit_due_at'] ?? null,
            'package_code' => $booking['package_code'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function notifyQuietly(array $booking, string $to, ?string $reason = null): void
    {
        $service = new BookingRequestService($this->app);
        if ($to === 'changed') {
            $service->notifyTransition($booking, 'changed', $reason);
            return;
        }
        $service->notifyTransition($booking, $to, $reason);
    }
}
