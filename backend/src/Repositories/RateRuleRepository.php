<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;

final class RateRuleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function allEnabled(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM rate_rules WHERE enabled = 1 ORDER BY priority DESC, id ASC'
        );
    }

    /** @return list<array<string, mixed>> */
    public function findByType(string $type): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM rate_rules WHERE enabled = 1 AND type = :type ORDER BY priority DESC, id ASC',
            ['type' => $type]
        );
    }

    /** @return list<array<string, mixed>> */
    public function findAllByType(string $type): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM rate_rules WHERE type = :type ORDER BY priority DESC, id ASC',
            ['type' => $type]
        );
    }

    /** @return list<array<string, mixed>> */
    public function findPackages(): array
    {
        return $this->findByType('package');
    }

    /** @return list<array<string, mixed>> */
    public function findFees(): array
    {
        return $this->findByType('fee');
    }

    /** @return list<array<string, mixed>> */
    public function findDateOverrides(): array
    {
        return $this->findByType('date_override');
    }

    /** @return list<array<string, mixed>> */
    public function findSeasonal(): array
    {
        return $this->findByType('seasonal');
    }

    /** @return list<array<string, mixed>> */
    public function findNightly(): array
    {
        return $this->findByType('nightly');
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = $this->db->now();
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = $this->db->now();
        }
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn($k) => ':' . $k, array_keys($data)));
        $this->db->query("INSERT INTO rate_rules ({$columns}) VALUES ({$placeholders})", $data);
        return $this->db->lastInsertId();
    }
}
