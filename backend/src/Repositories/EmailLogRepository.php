<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;

final class EmailLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = $this->db->now();
        }
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn($k) => ':' . $k, array_keys($data)));
        $this->db->query("INSERT INTO email_logs ({$columns}) VALUES ({$placeholders})", $data);
        return $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM email_logs WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        foreach (array_keys($fields) as $key) {
            $sets[] = "{$key} = :{$key}";
        }
        $fields['id'] = $id;
        $this->db->query('UPDATE email_logs SET ' . implode(', ', $sets) . ' WHERE id = :id', $fields);
    }

    /** @return list<array<string, mixed>> */
    public function findFailed(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM email_logs WHERE status = 'failed' ORDER BY created_at DESC LIMIT :limit",
            ['limit' => $limit]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forBooking(int $bookingId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM email_logs WHERE booking_id = :id ORDER BY created_at ASC',
            ['id' => $bookingId]
        );
    }
}
