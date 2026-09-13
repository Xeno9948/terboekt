<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;

final class CalendarConnectionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM calendar_connections WHERE id = :id', ['id' => $id]);
    }

    public function findByProvider(string $provider): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM calendar_connections WHERE provider = :provider LIMIT 1',
            ['provider' => $provider]
        );
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM calendar_connections ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function findEnabled(): array
    {
        return $this->db->fetchAll('SELECT * FROM calendar_connections WHERE enabled = 1 ORDER BY id');
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = $this->db->now();
        $sets = [];
        foreach (array_keys($fields) as $key) {
            $sets[] = "{$key} = :{$key}";
        }
        $fields['id'] = $id;
        $this->db->query('UPDATE calendar_connections SET ' . implode(', ', $sets) . ' WHERE id = :id', $fields);
    }

    /**
     * Add a connection (e.g. Booking.com later) without a code change.
     * @param array<string, mixed> $data
     */
    public function insert(array $data): array
    {
        $now = $this->db->now();
        $data += [
            'type' => 'ical_import',
            'enabled' => 1,
            'imported_event_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn($k) => ':' . $k, array_keys($data)));
        $this->db->query("INSERT INTO calendar_connections ({$columns}) VALUES ({$placeholders})", $data);
        $row = $this->findById($this->db->lastInsertId());
        if ($row === null) {
            throw new \RuntimeException('Calendar connection insert failed');
        }
        return $row;
    }
}
