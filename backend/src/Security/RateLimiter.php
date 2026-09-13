<?php
declare(strict_types=1);

namespace Terboekt\Security;

use Terboekt\Database;

final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    public function allow(string $action, string $ip, int $maxAttempts, int $windowSeconds): bool
    {
        $this->purge();
        $hash = $this->hash($ip);
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM auth_rate_limits
             WHERE action = :action AND ip_hash = :ip AND attempted_at >= :since',
            ['action' => $action, 'ip' => $hash, 'since' => $since]
        );
        return (int) ($row['c'] ?? 0) < $maxAttempts;
    }

    public function hit(string $action, string $ip): void
    {
        $this->db->query(
            'INSERT INTO auth_rate_limits (action, ip_hash, attempted_at) VALUES (:action, :ip, :at)',
            ['action' => $action, 'ip' => $this->hash($ip), 'at' => gmdate('Y-m-d H:i:s')]
        );
    }

    public function clear(string $action, string $ip): void
    {
        $this->db->query(
            'DELETE FROM auth_rate_limits WHERE action = :action AND ip_hash = :ip',
            ['action' => $action, 'ip' => $this->hash($ip)]
        );
    }

    private function hash(string $ip): string
    {
        return hash_hmac('sha256', $ip, AppKey::get() ?: 'terboekt-rate-limit');
    }

    private function purge(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
        $this->db->query('DELETE FROM auth_rate_limits WHERE attempted_at < :cutoff', ['cutoff' => $cutoff]);
    }
}
