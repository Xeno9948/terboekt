<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;
use Terboekt\DateRange;
use Terboekt\Domain\BookingStatus;

final class BookingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $len = strlen($alphabet);
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, $len - 1)];
            }
            $reference = 'BOOK-' . $suffix;
            if ($this->findByReference($reference) === null) {
                return $reference;
            }
        }
        throw new \RuntimeException('Unable to allocate a unique booking reference');
    }

    public function findById(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM bookings WHERE id = :id' . ($forUpdate ? $this->db->forUpdateSuffix() : '');
        return $this->db->fetchOne($sql, ['id' => $id]);
    }

    public function findByReference(string $reference, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM bookings WHERE reference = :reference' . ($forUpdate ? $this->db->forUpdateSuffix() : '');
        return $this->db->fetchOne($sql, ['reference' => $reference]);
    }

    public function findByIdOrReference(int|string $idOrRef, bool $forUpdate = false): ?array
    {
        if (is_int($idOrRef) || ctype_digit((string) $idOrRef)) {
            $byId = $this->findById((int) $idOrRef, $forUpdate);
            if ($byId !== null) {
                return $byId;
            }
        }
        return $this->findByReference((string) $idOrRef, $forUpdate);
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM bookings ORDER BY created_at DESC LIMIT :limit',
            ['limit' => $limit]
        );
    }

    /**
     * Overlap on half-open stay ranges: existing.check_in < newEnd AND existing.check_out > newStart.
     * @param list<string> $statuses
     * @return list<array<string, mixed>>
     */
    public function findOverlapping(string $checkIn, string $checkOut, array $statuses = BookingStatus::ACTIVE, ?int $excludeId = null): array
    {
        DateRange::of($checkIn, $checkOut);
        $placeholders = [];
        $params = ['check_in' => $checkIn, 'check_out' => $checkOut];
        foreach ($statuses as $i => $status) {
            $key = 'st' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }
        $sql = 'SELECT * FROM bookings
                WHERE status IN (' . implode(',', $placeholders) . ')
                  AND check_in < :check_out
                  AND check_out > :check_in';
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return $this->db->fetchAll($sql, $params);
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): array
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn($k) => ':' . $k, array_keys($data)));
        $this->db->query("INSERT INTO bookings ({$columns}) VALUES ({$placeholders})", $data);
        $id = $this->db->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new \RuntimeException('Booking insert failed');
        }
        return $row;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): array
    {
        $fields['updated_at'] = $this->db->now();
        $sets = [];
        foreach (array_keys($fields) as $key) {
            $sets[] = "{$key} = :{$key}";
        }
        $fields['id'] = $id;
        $this->db->query('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = :id', $fields);
        $row = $this->findById($id);
        if ($row === null) {
            throw new \RuntimeException('Booking not found after update');
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function findActiveInRange(string $from, string $to): array
    {
        return $this->findOverlapping($from, $to, BookingStatus::ACTIVE);
    }

    /**
     * REQUESTED or AWAITING_DEPOSIT whose payment deadline is in the past.
     *
     * @return list<array<string, mixed>>
     */
    public function findPastPaymentDue(string $now): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM bookings
             WHERE status IN ('REQUESTED', 'AWAITING_DEPOSIT')
               AND COALESCE(payment_due_at, deposit_due_at) IS NOT NULL
               AND COALESCE(payment_due_at, deposit_due_at) <= :now
             ORDER BY id ASC",
            ['now' => $now]
        );
    }

    /** @return list<array<string, mixed>> */
    public function findExpirable(string $now): array
    {
        return $this->findPastPaymentDue($now);
    }

    /** @return list<array<string, mixed>> */
    public function findRequestedOlderThan(string $cutoff): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM bookings WHERE status = 'REQUESTED' AND created_at < :cutoff",
            ['cutoff' => $cutoff]
        );
    }

    /** @return list<array<string, mixed>> */
    public function findAwaitingDepositPastDue(string $now): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM bookings
             WHERE status = 'AWAITING_DEPOSIT'
               AND COALESCE(payment_due_at, deposit_due_at) IS NOT NULL
               AND COALESCE(payment_due_at, deposit_due_at) <= :now",
            ['now' => $now]
        );
    }
}
