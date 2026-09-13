<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\Database;
use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\ConfirmationRequiresManagerException;
use Terboekt\Domain\IllegalTransitionException;
use Terboekt\Domain\NotFoundException;
use Terboekt\Repositories\AvailabilityBlockRepository;
use Terboekt\Repositories\BookingAuditRepository;
use Terboekt\Repositories\BookingRepository;
use Terboekt\Repositories\BookingStatusHistoryRepository;
use Terboekt\Repositories\OccupancyNightRepository;
use Terboekt\Repositories\PropertySettingsRepository;

final class BookingStatusService
{
    public function __construct(
        private readonly Database $db,
        private readonly BookingRepository $bookings,
        private readonly BookingStatusHistoryRepository $history,
        private readonly AvailabilityBlockRepository $blocks,
        private readonly PropertySettingsRepository $settings,
        private readonly OccupancyNightRepository $occupancy,
        private readonly BookingAuditRepository $audit,
    ) {
    }

    /**
     * Record the initial REQUESTED status. Call inside the booking-create transaction.
     */
    public function recordCreated(array $booking, string $actorType = 'guest', ?string $actorId = null): void
    {
        $this->history->record((int) $booking['id'], null, BookingStatus::REQUESTED, $actorType, $actorId, 'Booking created');
        $this->audit->record((int) $booking['id'], $actorType, $actorId, 'created', [
            'status' => BookingStatus::REQUESTED,
            'reference' => $booking['reference'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $extraFields Extra booking columns to update with the status change
     */
    public function transition(
        int|string $bookingIdOrRef,
        string $to,
        string $actorType,
        ?string $actorId = null,
        ?string $reason = null,
        array $extraFields = [],
    ): array {
        if (!BookingStatus::isKnown($to)) {
            throw IllegalTransitionException::from('?', $to);
        }
        if ($to === BookingStatus::CONFIRMED && $actorType !== 'admin') {
            throw new ConfirmationRequiresManagerException();
        }

        return $this->db->transaction(function () use ($bookingIdOrRef, $to, $actorType, $actorId, $reason, $extraFields) {
            $booking = $this->load($bookingIdOrRef, true);
            $from = (string) $booking['status'];
            if ($from === $to) {
                return $booking;
            }
            if (!BookingStatus::canTransition($from, $to)) {
                throw IllegalTransitionException::from($from, $to);
            }

            $fields = array_merge($extraFields, ['status' => $to]);
            if ($to === BookingStatus::AWAITING_DEPOSIT) {
                if (empty($booking['deposit_due_at']) && empty($extraFields['deposit_due_at'])) {
                    $fields['deposit_due_at'] = $booking['payment_due_at'] ?? $this->deadlineFromNow();
                }
                if (empty($booking['payment_due_at']) && empty($extraFields['payment_due_at'])) {
                    $fields['payment_due_at'] = $fields['deposit_due_at'] ?? $booking['deposit_due_at'] ?? $this->deadlineFromNow();
                }
            }
            if ($to === BookingStatus::CONFIRMED && empty($fields['deposit_received_at'])) {
                $fields['deposit_received_at'] = $this->db->now();
            }
            if (in_array($to, BookingStatus::TERMINAL, true)) {
                $this->blocks->deleteByBookingId((int) $booking['id']);
                $this->occupancy->releaseByBookingId((int) $booking['id']);
            }
            $updated = $this->bookings->update((int) $booking['id'], $fields);
            $this->history->record((int) $booking['id'], $from, $to, $actorType, $actorId, $reason);
            $this->audit->record((int) $booking['id'], $actorType, $actorId, 'status_change', [
                'from' => $from,
                'to' => $to,
                'reason' => $reason,
            ]);
            return $updated;
        });
    }

    /**
     * Expire REQUESTED / AWAITING_DEPOSIT past payment_due_at. Idempotent.
     *
     * @return list<array<string, mixed>> Newly expired bookings (empty on a second run)
     */
    public function expireOverdue(): array
    {
        $now = $this->db->now();
        $expired = [];
        foreach ($this->bookings->findPastPaymentDue($now) as $booking) {
            $updated = $this->expireOne((int) $booking['id'], $now);
            if ($updated !== null) {
                $expired[] = $updated;
            }
        }
        return $expired;
    }

    /**
     * @return array<string, mixed>|null The booking if it transitioned to EXPIRED this call
     */
    public function expireOne(int $id, ?string $now = null): ?array
    {
        $now ??= $this->db->now();
        return $this->db->transaction(function () use ($id, $now) {
            $booking = $this->load($id, true);
            $from = (string) $booking['status'];
            if (!in_array($from, [BookingStatus::REQUESTED, BookingStatus::AWAITING_DEPOSIT], true)) {
                return null;
            }
            $due = $booking['payment_due_at'] ?? $booking['deposit_due_at'] ?? null;
            if ($due === null || (string) $due > $now) {
                return null;
            }
            return $this->transition(
                (int) $booking['id'],
                BookingStatus::EXPIRED,
                'system',
                null,
                'Payment deadline passed'
            );
        });
    }

    public function canTransition(string $from, string $to): bool
    {
        return BookingStatus::canTransition($from, $to);
    }

    public function load(int|string $idOrRef, bool $forUpdate = false): array
    {
        $booking = $this->bookings->findByIdOrReference($idOrRef, $forUpdate);
        if ($booking === null) {
            throw new NotFoundException('Booking not found');
        }
        return $booking;
    }

    private function deadlineFromNow(): string
    {
        $days = max(1, $this->settings->depositDeadlineDays());
        return gmdate('Y-m-d H:i:s', time() + $days * 86400);
    }
}
