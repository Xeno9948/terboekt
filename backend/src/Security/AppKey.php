<?php
declare(strict_types=1);

namespace Terboekt\Security;

use Terboekt\Config;

final class AppKey
{
    public static function ensure(Config $config): string
    {
        $existing = \Terboekt\Env::get('APP_KEY');
        if ($existing) {
            return $existing;
        }
        $path = $config->storagePath . '/app.key';
        if (is_file($path)) {
            $key = trim((string) file_get_contents($path));
            if ($key !== '') {
                putenv('APP_KEY=' . $key);
                $_ENV['APP_KEY'] = $key;
                return $key;
            }
        }
        $key = bin2hex(random_bytes(32));
        if (!is_dir($config->storagePath)) {
            mkdir($config->storagePath, 0770, true);
        }
        file_put_contents($path, $key, LOCK_EX);
        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;
        return $key;
    }

    public static function get(): string
    {
        return (string) (\Terboekt\Env::get('APP_KEY') ?: '');
    }
}
