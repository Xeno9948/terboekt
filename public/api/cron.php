<?php
declare(strict_types=1);

/**
 * Combell / cPanel cron — hit this URL every 15 minutes (GET or POST):
 *
 *   https://hometerboekt.be/api/cron.php?token=YOUR_CRON_SECRET
 *   https://hometerboekt.be/api/cron/tick?token=YOUR_CRON_SECRET
 *
 * Token comes from CRON_SECRET in .env (falls back to ICAL_EXPORT_SECRET).
 * Never commit the token. This job expires unpaid REQUESTED / AWAITING_DEPOSIT
 * holds past payment_due_at and refreshes enabled iCal feeds. Idempotent.
 *
 * CLI equivalent (SSH): php backend/bin/cron.php
 */
$_SERVER['REQUEST_URI'] = '/api/cron/tick';
require __DIR__ . '/index.php';
