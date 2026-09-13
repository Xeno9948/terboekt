<?php
declare(strict_types=1);

use Terboekt\Database;
use Terboekt\DateRange;
use Terboekt\Domain\BookingStatus;

/**
 * Occupancy nights (unique per stay date), payment_due_at, audit log, availability mutex.
 *
 * @return callable(Database): void
 */
return static function (Database $db): void {
    $sqlite = $db->isSqlite();
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $dt = $sqlite ? 'TEXT' : 'DATETIME';
    $text = $sqlite ? 'TEXT' : 'VARCHAR(255)';
    $long = $sqlite ? 'TEXT' : 'TEXT';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->pdo()->exec("ALTER TABLE bookings ADD COLUMN payment_due_at {$dt} NULL");

    $db->pdo()->exec("
        CREATE TABLE occupancy_nights (
            stay_date VARCHAR(10) NOT NULL PRIMARY KEY,
            booking_id INTEGER NOT NULL,
            created_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE INDEX idx_occupancy_booking ON occupancy_nights (booking_id)');

    $db->pdo()->exec("
        CREATE TABLE booking_audit_log (
            id {$id},
            booking_id INTEGER NOT NULL,
            actor_type VARCHAR(16) NOT NULL,
            actor_id {$text} NULL,
            action VARCHAR(64) NOT NULL,
            details {$long} NULL,
            created_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE INDEX idx_audit_booking ON booking_audit_log (booking_id, created_at)');

    $db->pdo()->exec("
        CREATE TABLE availability_locks (
            id INTEGER NOT NULL PRIMARY KEY,
            name {$text} NOT NULL
        ){$engine}
    ");
    $db->query(
        'INSERT INTO availability_locks (id, name) VALUES (:id, :name)',
        ['id' => 1, 'name' => 'booking_dates']
    );

    if ($sqlite) {
        $db->pdo()->exec("UPDATE bookings SET payment_due_at = deposit_due_at WHERE payment_due_at IS NULL AND deposit_due_at IS NOT NULL");
        $db->pdo()->exec("UPDATE bookings SET payment_due_at = datetime(created_at, '+7 days') WHERE payment_due_at IS NULL");
    } else {
        $db->pdo()->exec("UPDATE bookings SET payment_due_at = deposit_due_at WHERE payment_due_at IS NULL AND deposit_due_at IS NOT NULL");
        $db->pdo()->exec("UPDATE bookings SET payment_due_at = DATE_ADD(created_at, INTERVAL 7 DAY) WHERE payment_due_at IS NULL");
    }
    $db->pdo()->exec('CREATE INDEX idx_bookings_payment_due ON bookings (status, payment_due_at)');

    $active = $db->fetchAll(
        "SELECT id, check_in, check_out FROM bookings WHERE status IN ('"
        . implode("','", BookingStatus::ACTIVE)
        . "')"
    );
    $now = $db->now();
    $insert = $db->pdo()->prepare(
        'INSERT INTO occupancy_nights (stay_date, booking_id, created_at) VALUES (:stay_date, :booking_id, :created_at)'
    );
    foreach ($active as $booking) {
        $range = DateRange::of((string) $booking['check_in'], (string) $booking['check_out']);
        foreach ($range->nightsList() as $day) {
            $insert->execute([
                'stay_date' => $day,
                'booking_id' => (int) $booking['id'],
                'created_at' => $now,
            ]);
        }
    }
};
