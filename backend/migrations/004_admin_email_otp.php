<?php
declare(strict_types=1);

use Terboekt\Database;

/**
 * Optional email OTP for admin login.
 *
 * @return callable(Database): void
 */
return static function (Database $db): void {
    $sqlite = $db->isSqlite();
    $dt = $sqlite ? 'TEXT' : 'DATETIME';
    $bool = $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0';
    $text = $sqlite ? 'TEXT' : 'VARCHAR(255)';

    $db->pdo()->exec("ALTER TABLE admin_users ADD COLUMN otp_enabled {$bool}");
    $db->pdo()->exec("ALTER TABLE admin_users ADD COLUMN otp_code_hash {$text} NULL");
    $db->pdo()->exec("ALTER TABLE admin_users ADD COLUMN otp_expires_at {$dt} NULL");
    $db->pdo()->exec('ALTER TABLE admin_users ADD COLUMN otp_attempts INTEGER NOT NULL DEFAULT 0');
};
