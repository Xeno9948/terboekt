<?php
declare(strict_types=1);

/**
 * Concurrent hold worker. Args: sqlite_path check_in check_out guest_email
 * Exit 0 = hold created, 2 = unavailable, 1 = other error.
 */
if ($argc < 5) {
    fwrite(STDERR, "usage: concurrent_hold_worker.php <sqlite> <check_in> <check_out> <email>\n");
    exit(1);
}

$path = $argv[1];
$checkIn = $argv[2];
$checkOut = $argv[3];
$email = $argv[4];

putenv('DATABASE_URL=sqlite:' . $path);
$_ENV['DATABASE_URL'] = 'sqlite:' . $path;
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
putenv('APP_BASE_URL=http://localhost:8080');
$_ENV['APP_BASE_URL'] = 'http://localhost:8080';
putenv('ICAL_EXPORT_SECRET=test-ical-secret');
$_ENV['ICAL_EXPORT_SECRET'] = 'test-ical-secret';
putenv('AIRBNB_ICAL_URL=https://example.test/airbnb.ics');
$_ENV['AIRBNB_ICAL_URL'] = 'https://example.test/airbnb.ics';
putenv('SMTP_HOST=');
putenv('SMTP_USERNAME=');
putenv('SMTP_PASSWORD=');
$_ENV['SMTP_HOST'] = '';
$_ENV['SMTP_USERNAME'] = '';
$_ENV['SMTP_PASSWORD'] = '';

$config = require dirname(__DIR__) . '/bootstrap.php';
$app = new Terboekt\App($config, new Terboekt\Database($config));

$nights = (new DateTimeImmutable($checkOut))->diff(new DateTimeImmutable($checkIn))->days;
$fields = [
    'guest_name' => 'Concurrent',
    'guest_email' => $email,
    'guest_phone' => null,
    'guest_message' => null,
    'language' => 'nl',
    'guests' => 2,
    'check_in' => $checkIn,
    'check_out' => $checkOut,
    'nights' => $nights,
    'sunday_evening_extra' => 0,
    'source' => 'test',
    'package_code' => 'weekend',
    'season' => 'high',
    'accommodation_cents' => 90000,
    'cleaning_fee_cents' => 10000,
    'tourist_tax_cents' => 600,
    'sunday_evening_cents' => 0,
    'extra_fees_cents' => 0,
    'discount_cents' => 0,
    'total_cents' => 100600,
    'deposit_cents' => 30180,
    'remaining_cents' => 70420,
    'security_deposit_cents' => 50000,
    'currency' => 'EUR',
    'pricing_snapshot' => '{}',
];

try {
    $app->availability()->createBookingHoldWithinTransaction($fields, function (array $created) use ($app): void {
        $app->status()->recordCreated($created, 'system');
    });
    fwrite(STDOUT, "OK\n");
    exit(0);
} catch (Terboekt\Domain\UnavailableException $e) {
    fwrite(STDOUT, "UNAVAILABLE\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
