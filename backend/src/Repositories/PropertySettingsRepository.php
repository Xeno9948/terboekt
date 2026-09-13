<?php
declare(strict_types=1);

namespace Terboekt\Repositories;

use Terboekt\Database;

final class PropertySettingsRepository
{
    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = $this->db->fetchAll('SELECT setting_key, setting_value FROM property_settings');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = $row['setting_value'] !== null ? (string) $row['setting_value'] : null;
        }
        $this->cache = $out;
        return $out;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();
        if (!array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }
        return $all[$key];
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value === null ? $default : (int) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $this->db->query(
            'INSERT INTO property_settings (setting_key, setting_value, updated_at)
             VALUES (:k, :v, :u)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value = :v2, updated_at = :u2',
            ['k' => $key, 'v' => $value, 'u' => $this->db->now(), 'v2' => $value, 'u2' => $this->db->now()]
        );
        $this->cache = null;
    }

    /**
     * Portable upsert for MySQL and SQLite.
     */
    public function upsert(string $key, ?string $value): void
    {
        $existing = $this->db->fetchOne(
            'SELECT setting_key FROM property_settings WHERE setting_key = :k',
            ['k' => $key]
        );
        if ($existing) {
            $this->db->query(
                'UPDATE property_settings SET setting_value = :v, updated_at = :u WHERE setting_key = :k',
                ['v' => $value, 'u' => $this->db->now(), 'k' => $key]
            );
        } else {
            $this->db->query(
                'INSERT INTO property_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)',
                ['k' => $key, 'v' => $value, 'u' => $this->db->now()]
            );
        }
        $this->cache = null;
    }

    public function depositPercentage(): int
    {
        return $this->getInt('deposit_percentage', 30);
    }

    public function depositDeadlineDays(): int
    {
        return $this->getInt('deposit_deadline_days', 7);
    }

    public function currency(): string
    {
        return $this->get('currency', 'EUR') ?? 'EUR';
    }

    public function maxGuests(): int
    {
        return $this->getInt('max_guests', 8);
    }
}
