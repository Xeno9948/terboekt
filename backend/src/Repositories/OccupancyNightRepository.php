<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use PDOException;
use Terboekt\Database;
use Terboekt\DateRange;
use Terboekt\Domain\UnavailableException;

final class OccupancyNightRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Reserve [checkIn, checkOut) nights. Unique(stay_date) is the concurrency backstop.
     */
    public function reserve(int $bookingId, string $checkIn, string $checkOut): void
    {
        $range = DateRange::of($checkIn, $checkOut);
        $now = $this->db->now();
        try {
            foreach ($range->nightsList() as $day) {
                $this->db->query(
                    'INSERT INTO occupancy_nights (stay_date, booking_id, created_at)
                     VALUES (:stay_date, :booking_id, :created_at)',
                    [
                        'stay_date' => $day,
                        'booking_id' => $bookingId,
                        'created_at' => $now,
                    ]
                );
            }
        } catch (PDOException $e) {
            if ($this->db->isUniqueConstraintViolation($e)) {
                throw new UnavailableException('The selected dates are not available');
            }
            throw $e;
        }
    }

    public function releaseByBookingId(int $bookingId): void
    {
        $this->db->query(
            'DELETE FROM occupancy_nights WHERE booking_id = :id',
            ['id' => $bookingId]
        );
    }

    public function replace(int $bookingId, string $checkIn, string $checkOut): void
    {
        $this->releaseByBookingId($bookingId);
        $this->reserve($bookingId, $checkIn, $checkOut);
    }

    public function hasOverlap(string $checkIn, string $checkOut, ?int $excludeBookingId = null): bool
    {
        DateRange::of($checkIn, $checkOut);
        $sql = 'SELECT stay_date FROM occupancy_nights
                WHERE stay_date >= :check_in AND stay_date < :check_out';
        $params = ['check_in' => $checkIn, 'check_out' => $checkOut];
        if ($excludeBookingId !== null) {
            $sql .= ' AND booking_id != :exclude_id';
            $params['exclude_id'] = $excludeBookingId;
        }
        $sql .= ' LIMIT 1';
        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Lock existing occupancy rows in the range (MySQL FOR UPDATE). SQLite relies on BEGIN IMMEDIATE.
     */
    public function lockRange(string $checkIn, string $checkOut): void
    {
        DateRange::of($checkIn, $checkOut);
        $sql = 'SELECT stay_date FROM occupancy_nights
                WHERE stay_date >= :check_in AND stay_date < :check_out'
            . $this->db->forUpdateSuffix();
        $this->db->query($sql, ['check_in' => $checkIn, 'check_out' => $checkOut]);
    }

    /** @return list<string> */
    public function nightsForBooking(int $bookingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT stay_date FROM occupancy_nights WHERE booking_id = :id ORDER BY stay_date',
            ['id' => $bookingId]
        );
        return array_map(static fn(array $row): string => (string) $row['stay_date'], $rows);
    }
}
