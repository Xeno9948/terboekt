<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;
use Terboekt\DateRange;

final class AvailabilityBlockRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM availability_blocks WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findOverlapping(string $start, string $end, ?string $source = null): array
    {
        DateRange::of($start, $end);
        $sql = 'SELECT * FROM availability_blocks
                WHERE start_date < :end_date AND end_date > :start_date';
        $params = ['start_date' => $start, 'end_date' => $end];
        if ($source !== null) {
            $sql .= ' AND source = :source';
            $params['source'] = $source;
        }
        return $this->db->fetchAll($sql, $params);
    }

    /** @return list<array<string, mixed>> */
    public function findInRange(string $start, string $end): array
    {
        return $this->findOverlapping($start, $end);
    }

    /** @return list<array<string, mixed>> */
    public function findManual(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM availability_blocks WHERE source = 'manual' ORDER BY start_date"
        );
    }

    /** Blocks we publish outbound (never imported iCal — that would loop back to Airbnb). */
    public function findForOutboundExport(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM availability_blocks
             WHERE source IN ('manual', 'owner', 'maintenance')
             ORDER BY start_date"
        );
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): array
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = $this->db->now();
        }
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn($k) => ':' . $k, array_keys($data)));
        $this->db->query("INSERT INTO availability_blocks ({$columns}) VALUES ({$placeholders})", $data);
        $id = $this->db->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new \RuntimeException('Availability block insert failed');
        }
        return $row;
    }

    public function deleteByBookingId(int $bookingId): void
    {
        $this->db->query(
            "DELETE FROM availability_blocks WHERE booking_id = :id AND source = 'booking'",
            ['id' => $bookingId]
        );
    }

    public function updateByBookingId(int $bookingId, string $start, string $end): void
    {
        DateRange::of($start, $end);
        $this->db->query(
            "UPDATE availability_blocks
             SET start_date = :start_date, end_date = :end_date
             WHERE booking_id = :id AND source = 'booking'",
            [
                'start_date' => $start,
                'end_date' => $end,
                'id' => $bookingId,
            ]
        );
    }

    public function deleteImportedForConnection(int $connectionId): void
    {
        $this->db->query(
            "DELETE FROM availability_blocks WHERE calendar_connection_id = :id AND source = 'ical'",
            ['id' => $connectionId]
        );
    }

    public function countImported(int $connectionId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM availability_blocks WHERE calendar_connection_id = :id AND source = 'ical'",
            ['id' => $connectionId]
        );
        return (int) ($row['c'] ?? 0);
    }
}
