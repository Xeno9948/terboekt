<?php
declare(strict_types=1);

use Terboekt\Database;

/**
 * @return callable(Database): void
 */
return static function (Database $db): void {
    $sqlite = $db->isSqlite();
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $dt = $sqlite ? 'TEXT' : 'DATETIME';
    $bool = $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0';
    $text = $sqlite ? 'TEXT' : 'VARCHAR(255)';
    $long = $sqlite ? 'TEXT' : 'TEXT';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->pdo()->exec("
        CREATE TABLE property_settings (
            setting_key {$text} NOT NULL PRIMARY KEY,
            setting_value {$long} NULL,
            updated_at {$dt} NOT NULL
        ){$engine}
    ");

    $db->pdo()->exec("
        CREATE TABLE admin_users (
            id {$id},
            email {$text} NOT NULL,
            password_hash {$text} NOT NULL,
            role {$text} NOT NULL DEFAULT 'admin',
            display_name {$text} NULL,
            is_active {$bool},
            last_login_at {$dt} NULL,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE UNIQUE INDEX idx_admin_users_email ON admin_users (email)');
    $db->pdo()->exec('CREATE INDEX idx_admin_users_role ON admin_users (role)');

    $db->pdo()->exec("
        CREATE TABLE bookings (
            id {$id},
            reference VARCHAR(16) NOT NULL,
            status VARCHAR(32) NOT NULL,
            guest_name {$text} NOT NULL,
            guest_email {$text} NOT NULL,
            guest_phone {$text} NULL,
            guest_message {$long} NULL,
            language VARCHAR(8) NOT NULL DEFAULT 'nl',
            guests INTEGER NOT NULL,
            check_in VARCHAR(10) NOT NULL,
            check_out VARCHAR(10) NOT NULL,
            nights INTEGER NOT NULL,
            sunday_evening_extra {$bool},
            source VARCHAR(32) NOT NULL DEFAULT 'website',
            package_code {$text} NULL,
            season VARCHAR(16) NULL,
            accommodation_cents INTEGER NOT NULL DEFAULT 0,
            cleaning_fee_cents INTEGER NOT NULL DEFAULT 0,
            tourist_tax_cents INTEGER NOT NULL DEFAULT 0,
            sunday_evening_cents INTEGER NOT NULL DEFAULT 0,
            extra_fees_cents INTEGER NOT NULL DEFAULT 0,
            discount_cents INTEGER NOT NULL DEFAULT 0,
            total_cents INTEGER NOT NULL DEFAULT 0,
            deposit_cents INTEGER NOT NULL DEFAULT 0,
            remaining_cents INTEGER NOT NULL DEFAULT 0,
            security_deposit_cents INTEGER NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
            pricing_snapshot {$long} NULL,
            deposit_due_at {$dt} NULL,
            deposit_received_at {$dt} NULL,
            manager_notes {$long} NULL,
            rejection_reason {$long} NULL,
            cancellation_reason {$long} NULL,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE UNIQUE INDEX idx_bookings_reference ON bookings (reference)');
    $db->pdo()->exec('CREATE INDEX idx_bookings_status_dates ON bookings (status, check_in, check_out)');
    $db->pdo()->exec('CREATE INDEX idx_bookings_dates ON bookings (check_in, check_out)');
    $db->pdo()->exec('CREATE INDEX idx_bookings_email ON bookings (guest_email)');
    $db->pdo()->exec('CREATE INDEX idx_bookings_created ON bookings (created_at)');

    $db->pdo()->exec("
        CREATE TABLE booking_status_history (
            id {$id},
            booking_id INTEGER NOT NULL,
            from_status VARCHAR(32) NULL,
            to_status VARCHAR(32) NOT NULL,
            actor_type VARCHAR(16) NOT NULL,
            actor_id {$text} NULL,
            reason {$long} NULL,
            created_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE INDEX idx_status_history_booking ON booking_status_history (booking_id, created_at)');

    $db->pdo()->exec("
        CREATE TABLE calendar_connections (
            id {$id},
            provider VARCHAR(32) NOT NULL,
            name {$text} NOT NULL,
            type VARCHAR(32) NOT NULL DEFAULT 'ical_import',
            url {$long} NULL,
            url_env_key {$text} NULL,
            enabled {$bool},
            last_sync_attempt_at {$dt} NULL,
            last_successful_sync_at {$dt} NULL,
            last_error {$long} NULL,
            imported_event_count INTEGER NOT NULL DEFAULT 0,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE UNIQUE INDEX idx_calendar_provider_name ON calendar_connections (provider, name)');
    $db->pdo()->exec('CREATE INDEX idx_calendar_enabled ON calendar_connections (enabled)');

    $db->pdo()->exec("
        CREATE TABLE availability_blocks (
            id {$id},
            start_date VARCHAR(10) NOT NULL,
            end_date VARCHAR(10) NOT NULL,
            source VARCHAR(32) NOT NULL,
            booking_id INTEGER NULL,
            calendar_connection_id INTEGER NULL,
            external_uid {$text} NULL,
            notes {$long} NULL,
            created_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE INDEX idx_blocks_dates ON availability_blocks (start_date, end_date)');
    $db->pdo()->exec('CREATE INDEX idx_blocks_source ON availability_blocks (source, calendar_connection_id)');
    $db->pdo()->exec('CREATE INDEX idx_blocks_booking ON availability_blocks (booking_id)');
    $db->pdo()->exec('CREATE INDEX idx_blocks_uid ON availability_blocks (calendar_connection_id, external_uid)');

    $db->pdo()->exec("
        CREATE TABLE rate_rules (
            id {$id},
            type VARCHAR(32) NOT NULL,
            code {$text} NOT NULL,
            name {$text} NOT NULL,
            season VARCHAR(16) NULL,
            checkin_weekday INTEGER NULL,
            checkout_weekday INTEGER NULL,
            nights INTEGER NULL,
            min_nights INTEGER NULL,
            max_nights INTEGER NULL,
            start_date VARCHAR(10) NULL,
            end_date VARCHAR(10) NULL,
            days_of_week {$text} NULL,
            amount_cents INTEGER NULL,
            amount_percent INTEGER NULL,
            calculation VARCHAR(32) NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            enabled {$bool},
            extra_guest_threshold INTEGER NULL,
            extra_guest_cents INTEGER NULL,
            notes {$long} NULL,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE INDEX idx_rate_rules_type ON rate_rules (type, enabled, priority)');
    $db->pdo()->exec('CREATE INDEX idx_rate_rules_dates ON rate_rules (start_date, end_date)');
    $db->pdo()->exec('CREATE INDEX idx_rate_rules_code ON rate_rules (code)');

    $db->pdo()->exec("
        CREATE TABLE email_logs (
            id {$id},
            booking_id INTEGER NULL,
            template_key VARCHAR(64) NOT NULL,
            to_email {$text} NOT NULL,
            subject {$text} NOT NULL,
            body_html {$long} NULL,
            body_text {$long} NULL,
            status VARCHAR(16) NOT NULL,
            error {$long} NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_attempt_at {$dt} NULL,
            sent_at {$dt} NULL,
            created_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE INDEX idx_email_logs_booking ON email_logs (booking_id, created_at)');
    $db->pdo()->exec('CREATE INDEX idx_email_logs_status ON email_logs (status, created_at)');

    $db->pdo()->exec("
        CREATE TABLE auth_rate_limits (
            id {$id},
            action VARCHAR(64) NOT NULL,
            ip_hash VARCHAR(64) NOT NULL,
            attempted_at {$dt} NOT NULL
        ){$engine}
    ");
    $db->pdo()->exec('CREATE INDEX idx_rate_limits_lookup ON auth_rate_limits (action, ip_hash, attempted_at)');
};
