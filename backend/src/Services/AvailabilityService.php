<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\Database;
use Terboekt\DateRange;
use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\UnavailableException;
use Terboekt\Repositories\AvailabilityBlockRepository;
use Terboekt\Repositories\BookingRepository;
use Terboekt\Repositories\CalendarConnectionRepository;
use Terboekt\Repositories\OccupancyNightRepository;
use Terboekt\Repositories\PropertySettingsRepository;

final class AvailabilityService
{
    public function __construct(
        private readonly Database $db,
        private readonly BookingRepository $bookings,
        private readonly AvailabilityBlockRepository $blocks,
        private readonly CalendarConnectionRepository $calendars,
        private readonly OccupancyNightRepository $occupancy,
        private readonly PropertySettingsRepository $settings,
    ) {
    }

    /**
     * Combine external iCal, confirmed bookings, pending (REQUESTED + AWAITING_DEPOSIT), and manual blocks.
     * Stay dates are half-open [check_in, check_out). Stale/failed iCal sync does not treat dates as free.
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   unavailable: list<array{start: string, end: string, source: string}>,
     *   days: array<string, bool>,
     *   calendar_health: array<string, mixed>,
     *   calendar_reliable: bool
     * }
     */
    public function getAvailability(string $from, string $to): array
    {
        $range = DateRange::of($from, $to);
        $unavailable = [];

        foreach ($this->bookings->findOverlapping($from, $to, BookingStatus::ACTIVE) as $booking) {
            $unavailable[] = [
                'start' => (string) $booking['check_in'],
                'end' => (string) $booking['check_out'],
                'source' => 'booking',
            ];
        }
        foreach ($this->blocks->findOverlapping($from, $to) as $block) {
            $unavailable[] = [
                'start' => (string) $block['start_date'],
                'end' => (string) $block['end_date'],
                'source' => (string) $block['source'],
            ];
        }

        $reliable = $this->isExternalCalendarReliable();
        if (!$reliable) {
            $unavailable[] = [
                'start' => $from,
                'end' => $to,
                'source' => 'calendar_stale',
            ];
        }

        $days = [];
        foreach ($range->nightsList() as $day) {
            $next = DateRange::addDays($day, 1);
            $days[$day] = $this->isRangeAvailable($day, $next);
        }

        return [
            'from' => $from,
            'to' => $to,
            'unavailable' => $unavailable,
            'days' => $days,
            'calendar_health' => $this->calendarHealthSummary(),
            'calendar_reliable' => $reliable,
        ];
    }

    public function isRangeAvailable(
        string $checkIn,
        string $checkOut,
        ?int $excludeBookingId = null,
        bool $enforceCalendarHealth = true,
    ): bool
    {
        DateRange::of($checkIn, $checkOut);
        if ($enforceCalendarHealth && !$this->isExternalCalendarReliable()) {
            return false;
        }
        if ($this->occupancy->hasOverlap($checkIn, $checkOut, $excludeBookingId)) {
            return false;
        }
        if ($this->bookings->findOverlapping($checkIn, $checkOut, BookingStatus::ACTIVE, $excludeBookingId) !== []) {
            return false;
        }
        if ($this->blocks->findOverlapping($checkIn, $checkOut) !== []) {
            if ($excludeBookingId === null) {
                return false;
            }
            foreach ($this->blocks->findOverlapping($checkIn, $checkOut) as $block) {
                $blockBookingId = $block['booking_id'] ?? null;
                if ($blockBookingId === null || (int) $blockBookingId !== $excludeBookingId) {
                    return false;
                }
            }
        }
        return true;
    }

    public function assertRangeAvailable(
        string $checkIn,
        string $checkOut,
        ?int $excludeBookingId = null,
        bool $enforceCalendarHealth = true,
    ): void
    {
        if ($enforceCalendarHealth && !$this->isExternalCalendarReliable()) {
            throw new UnavailableException(
                'The selected dates are not available (external calendar sync is stale or failed)'
            );
        }
        if (!$this->isRangeAvailable($checkIn, $checkOut, $excludeBookingId, $enforceCalendarHealth)) {
            throw new UnavailableException('The selected dates are not available');
        }
    }

    /**
     * Insert booking + occupancy nights + occupancy block atomically.
     * Caller supplies fully priced booking fields (without id/reference/timestamps).
     * Never sets status to CONFIRMED. Guest-supplied totals must not be passed in.
     *
     * @param array<string, mixed> $bookingFields
     * @return array<string, mixed>
     */
    public function createBookingHoldWithinTransaction(array $bookingFields, callable $afterInsert): array
    {
        return $this->db->transaction(function () use ($bookingFields, $afterInsert) {
            $this->db->lockScheduling();
            $checkIn = (string) $bookingFields['check_in'];
            $checkOut = (string) $bookingFields['check_out'];
            $this->occupancy->lockRange($checkIn, $checkOut);
            $this->assertRangeAvailable($checkIn, $checkOut);

            if (!isset($bookingFields['reference'])) {
                $bookingFields['reference'] = $this->bookings->generateReference();
            }
            $bookingFields['status'] = BookingStatus::REQUESTED;
            $now = $this->db->now();
            $bookingFields['created_at'] = $now;
            $bookingFields['updated_at'] = $now;
            if (empty($bookingFields['payment_due_at'])) {
                $due = $this->paymentDueFrom($now);
                $bookingFields['payment_due_at'] = $due;
                if (empty($bookingFields['deposit_due_at'])) {
                    $bookingFields['deposit_due_at'] = $due;
                }
            } elseif (empty($bookingFields['deposit_due_at'])) {
                $bookingFields['deposit_due_at'] = $bookingFields['payment_due_at'];
            }
            $booking = $this->bookings->insert($bookingFields);
            $this->occupancy->reserve((int) $booking['id'], $checkIn, $checkOut);
            $this->blocks->insert([
                'start_date' => $checkIn,
                'end_date' => $checkOut,
                'source' => 'booking',
                'booking_id' => (int) $booking['id'],
                'calendar_connection_id' => null,
                'external_uid' => $booking['reference'],
                'notes' => null,
            ]);
            $afterInsert($booking);
            return $booking;
        });
    }

    /**
     * Revalidate and move occupancy for an existing hold/booking (admin date change).
     */
    public function moveHoldWithinTransaction(int $bookingId, string $checkIn, string $checkOut): void
    {
        $this->db->lockScheduling();
        $this->occupancy->lockRange($checkIn, $checkOut);
        $this->assertRangeAvailable($checkIn, $checkOut, $bookingId);
        $this->occupancy->replace($bookingId, $checkIn, $checkOut);
        $this->blocks->updateByBookingId($bookingId, $checkIn, $checkOut);
    }

    public function releaseHoldOccupancy(int $bookingId): void
    {
        $this->occupancy->releaseByBookingId($bookingId);
        $this->blocks->deleteByBookingId($bookingId);
    }

    /** @return list<array{start: string, end: string}> */
    public function getExternalAvailability(string $from, string $to): array
    {
        $out = [];
        foreach ($this->blocks->findOverlapping($from, $to, 'ical') as $block) {
            $out[] = [
                'start' => (string) $block['start_date'],
                'end' => (string) $block['end_date'],
            ];
        }
        return $out;
    }

    public function isExternalCalendarReliable(): bool
    {
        foreach ($this->calendars->findEnabled() as $c) {
            if ($this->connectionMakesDatesUnreliable($c)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function calendarHealthSummary(): array
    {
        $connections = $this->calendars->all();
        $items = [];
        $anyStale = false;
        $anyError = false;
        foreach ($connections as $c) {
            $success = $c['last_successful_sync_at'] ?? null;
            $attempt = $c['last_sync_attempt_at'] ?? null;
            $stale = $attempt !== null && ($success === null || $success < $attempt);
            if ($stale) {
                $anyStale = true;
            }
            if (!empty($c['last_error'])) {
                $anyError = true;
            }
            $items[] = [
                'id' => (int) $c['id'],
                'provider' => $c['provider'],
                'name' => $c['name'],
                'enabled' => (bool) $c['enabled'],
                'has_url' => $c['url'] !== null && $c['url'] !== '' || $c['url_env_key'] !== null,
                'last_sync_attempt_at' => $c['last_sync_attempt_at'],
                'last_successful_sync_at' => $c['last_successful_sync_at'],
                'last_error' => $c['last_error'],
                'imported_event_count' => (int) $c['imported_event_count'],
                'stale' => $stale,
                // URL omitted on purpose
            ];
        }
        return [
            'ok' => !$anyError && !$anyStale,
            'stale' => $anyStale,
            'reliable' => $this->isExternalCalendarReliable(),
            'connections' => $items,
        ];
    }

    public function paymentDueFrom(string $fromUtc): string
    {
        $days = max(1, $this->settings->depositDeadlineDays());
        $created = new \DateTimeImmutable($fromUtc, new \DateTimeZone('UTC'));
        return $created->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $c */
    private function connectionMakesDatesUnreliable(array $c): bool
    {
        if (!(int) $c['enabled']) {
            return false;
        }
        $success = $c['last_successful_sync_at'] ?? null;
        $attempt = $c['last_sync_attempt_at'] ?? null;
        if ($attempt === null) {
            return false;
        }
        return $success === null || $success < $attempt;
    }
}
