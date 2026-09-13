<?php
declare(strict_types=1);

namespace Terboekt\Security;

use Terboekt\Config;

final class SessionStore
{
    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = $config->storagePath . '/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('terboekt_admin');
        session_save_path($dir);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }
}
