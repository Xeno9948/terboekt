<?php
declare(strict_types=1);

/**
 * CLI cron: expire unpaid holds + refresh iCal feeds.
 * Prefer HTTP on Combell: GET /api/cron.php?token=CRON_SECRET
 */
$config = require dirname(__DIR__) . '/bootstrap.php';
$app = new Terboekt\App($config, new Terboekt\Database($config));
(new Terboekt\Migrator($app->db))->migrate();

$expired = $app->expiry()->run();
$sync = $app->calendarSync()->refreshAllEnabledCalendars();
fwrite(STDOUT, 'Expired ' . $expired['expired'] . " booking(s).\n");
fwrite(STDOUT, json_encode([
    'expired' => $expired,
    'calendars' => $sync,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
