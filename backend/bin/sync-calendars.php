<?php
declare(strict_types=1);

/**
 * Manual calendar refresh + expiry. Prefer HTTP cron:
 *   GET {APP_BASE_URL}/api/cron.php?token=CRON_SECRET
 * so Combell can use a URL cron job. CLI: php backend/bin/cron.php
 */
$config = require dirname(__DIR__) . '/bootstrap.php';
$app = new Terboekt\App($config, new Terboekt\Database($config));
(new Terboekt\Migrator($app->db))->migrate();

$expired = $app->expiry()->run();
$sync = $app->calendarSync()->refreshAllEnabledCalendars();
fwrite(STDOUT, 'Expired ' . $expired['expired'] . " booking(s).\n");
fwrite(STDOUT, json_encode($sync, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
