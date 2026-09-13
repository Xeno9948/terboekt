<?php
declare(strict_types=1);

namespace Terboekt;

use RuntimeException;

final class Migrator
{
    public function __construct(private readonly Database $db)
    {
    }

    public function migrate(): void
    {
        $this->ensureRegistry();
        $dir = TERBOEKT_BACKEND . '/migrations';
        $files = glob($dir . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $applied = $this->applied();
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (isset($applied[$version])) {
                continue;
            }
            $callback = require $file;
            if (!is_callable($callback)) {
                throw new RuntimeException('Migration must return a callable: ' . $version);
            }
            $this->db->transaction(function () use ($callback, $version): void {
                $callback($this->db);
                $this->db->query(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)',
                    ['version' => $version, 'applied_at' => $this->db->now()]
                );
            });
        }
    }

    /** @return array<string, true> */
    public function applied(): array
    {
        $rows = $this->db->fetchAll('SELECT version FROM schema_migrations ORDER BY version');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['version']] = true;
        }
        return $out;
    }

    private function ensureRegistry(): void
    {
        if ($this->db->isSqlite()) {
            $this->db->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version TEXT PRIMARY KEY,
                    applied_at TEXT NOT NULL
                )'
            );
            return;
        }
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
