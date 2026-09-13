<?php
declare(strict_types=1);

namespace Terboekt;

final class Config
{
    public function __construct(
        public readonly string $appBaseUrl,
        public readonly string $appEnv,
        public readonly string $timezone,
        public readonly string $databaseUrl,
        public readonly ?string $airbnbIcalUrl,
        public readonly ?string $icalExportSecret,
        public readonly ?string $cronSecret,
        public readonly ?string $smtpHost,
        public readonly int $smtpPort,
        public readonly string $smtpSecure,
        public readonly ?string $smtpUsername,
        public readonly ?string $smtpPassword,
        public readonly string $smtpFromEmail,
        public readonly string $smtpFromName,
        public readonly string $smtpReplyTo,
        public readonly string $managerEmail,
        public readonly string $publicRoot,
        public readonly string $backendRoot,
        public readonly string $storagePath,
    ) {
    }

    public static function fromEnv(): self
    {
        $databaseUrl = self::resolveDatabaseUrl();
        $smtpSecure = strtolower((string) Env::get('SMTP_SECURE', 'tls'));
        $smtpPort = (int) (Env::get('SMTP_PORT', '587') ?? '587');

        return new self(
            appBaseUrl: rtrim(self::resolveAppBaseUrl(), '/'),
            appEnv: (string) Env::get('APP_ENV', 'local'),
            timezone: (string) Env::get('APP_TIMEZONE', 'Europe/Brussels'),
            databaseUrl: $databaseUrl,
            airbnbIcalUrl: Env::get('AIRBNB_ICAL_URL'),
            icalExportSecret: Env::get('ICAL_EXPORT_SECRET'),
            cronSecret: Env::get('CRON_SECRET') ?: Env::get('ICAL_EXPORT_SECRET'),
            smtpHost: Env::get('SMTP_HOST'),
            smtpPort: $smtpPort,
            smtpSecure: $smtpSecure,
            smtpUsername: Env::get('SMTP_USERNAME'),
            smtpPassword: Env::get('SMTP_PASSWORD'),
            smtpFromEmail: (string) Env::get('SMTP_FROM_EMAIL', 'info@hometerboekt.be'),
            smtpFromName: (string) Env::get('SMTP_FROM_NAME', 'Home Terboekt'),
            smtpReplyTo: (string) Env::get('SMTP_REPLY_TO', 'info@hometerboekt.be'),
            managerEmail: (string) Env::get('MANAGER_EMAIL', 'info@hometerboekt.be'),
            publicRoot: TERBOEKT_PUBLIC,
            backendRoot: TERBOEKT_BACKEND,
            storagePath: TERBOEKT_BACKEND . '/storage',
        );
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    public function smtpConfigured(): bool
    {
        return $this->smtpHost !== null && $this->smtpHost !== ''
            && $this->smtpUsername !== null && $this->smtpUsername !== ''
            && $this->smtpPassword !== null && $this->smtpPassword !== '';
    }

    public function ratesJsonPath(): string
    {
        return $this->publicRoot . '/assets/data/rates.json';
    }

    private static function resolveAppBaseUrl(): string
    {
        $explicit = Env::get('APP_BASE_URL');
        if ($explicit) {
            return $explicit;
        }
        $railway = Env::get('RAILWAY_PUBLIC_DOMAIN');
        if ($railway) {
            return 'https://' . $railway;
        }
        return 'http://localhost:8080';
    }

    private static function resolveDatabaseUrl(): string
    {
        $url = Env::get('DATABASE_URL') ?: Env::get('MYSQL_URL');
        if ($url) {
            if (str_starts_with($url, 'sqlite:') && !str_starts_with($url, 'sqlite:/') && !str_starts_with($url, 'sqlite::memory:')) {
                $relative = substr($url, strlen('sqlite:'));
                if (!str_starts_with($relative, '/')) {
                    $url = 'sqlite:' . TERBOEKT_REPO . '/' . $relative;
                }
            }
            return $url;
        }

        $host = Env::get('MYSQLHOST') ?: Env::get('DB_HOST');
        $name = Env::get('MYSQLDATABASE') ?: Env::get('DB_NAME');
        $user = Env::get('MYSQLUSER') ?: Env::get('DB_USER');
        if ($host && $name && $user !== null) {
            $pass = rawurlencode((string) (Env::get('MYSQLPASSWORD') ?: Env::get('DB_PASSWORD', '')));
            $userEnc = rawurlencode($user);
            $port = Env::get('MYSQLPORT') ?: Env::get('DB_PORT', '3306');
            return "mysql://{$userEnc}:{$pass}@{$host}:{$port}/{$name}";
        }

        return 'sqlite:' . TERBOEKT_BACKEND . '/storage/terboekt.sqlite';
    }
}
