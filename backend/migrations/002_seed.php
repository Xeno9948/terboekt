<?php
declare(strict_types=1);

use Terboekt\Database;

/**
 * Seeds PropertySettings, published RateRules, and the Airbnb CalendarConnection.
 * Airbnb URL is never stored; it is read from AIRBNB_ICAL_URL at sync time.
 *
 * @return callable(Database): void
 */
return static function (Database $db): void {
    $now = $db->now();
    $ratesPath = TERBOEKT_PUBLIC . '/assets/data/rates.json';
    if (!is_file($ratesPath)) {
        throw new RuntimeException('Missing published rates file: ' . $ratesPath);
    }
    $json = json_decode((string) file_get_contents($ratesPath), true, 512, JSON_THROW_ON_ERROR);

    $settings = [
        'deposit_percentage' => (string) (int) $json['depositPercentage'],
        'deposit_deadline_days' => (string) (int) $json['depositDeadlineDays'],
        'request_expiry_days' => (string) (int) $json['depositDeadlineDays'],
        'currency' => (string) $json['currency'],
        'max_guests' => (string) (int) $json['maxGuests'],
        'bedrooms' => (string) (int) $json['bedrooms'],
        'cleaning_fee_cents' => (string) (int) $json['fees']['cleaningCents'],
        'tourist_tax_per_person_per_night_cents' => (string) (int) $json['fees']['touristTaxPerPersonPerNightCents'],
        'security_deposit_cents' => (string) (int) $json['fees']['securityDepositCents'],
        'sunday_evening_extra_cents' => (string) (int) $json['fees']['sundayEveningExtraCents'],
        'checkin_from' => (string) $json['checkinFrom'],
        'checkout_before' => (string) $json['checkoutBefore'],
        'property_name' => (string) $json['propertyName'],
        'property_address' => (string) $json['address'],
        'contact_email' => (string) $json['email'],
        'house_rules_url' => (string) $json['houseRulesUrl'],
        'privacy_url' => (string) ($json['privacyUrl'] ?? '/cookies'),
        'cancellation_url' => (string) ($json['cancellationUrl'] ?? '/annulatie'),
        'analytics_id' => '',
        'timezone' => (string) $json['timezone'],
        // Bank details stay empty until the manager configures them in admin.
        'bank_account_holder' => '',
        'bank_iban' => '',
        'bank_bic' => '',
        'bank_name' => '',
        'default_nightly_cents' => '',
    ];

    $insertSetting = $db->pdo()->prepare(
        'INSERT INTO property_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :u)'
    );
    foreach ($settings as $key => $value) {
        $insertSetting->execute(['k' => $key, 'v' => $value, 'u' => $now]);
    }

    $insertRule = $db->pdo()->prepare(
        'INSERT INTO rate_rules (
            type, code, name, season, checkin_weekday, checkout_weekday, nights,
            min_nights, max_nights, start_date, end_date, days_of_week,
            amount_cents, amount_percent, calculation, priority, enabled,
            extra_guest_threshold, extra_guest_cents, notes, created_at, updated_at
        ) VALUES (
            :type, :code, :name, :season, :checkin_weekday, :checkout_weekday, :nights,
            :min_nights, :max_nights, :start_date, :end_date, :days_of_week,
            :amount_cents, :amount_percent, :calculation, :priority, :enabled,
            :extra_guest_threshold, :extra_guest_cents, :notes, :created_at, :updated_at
        )'
    );

    $enabled = 1;
    foreach ($json['packages'] as $package) {
        foreach (['low' => 'lowCents', 'high' => 'highCents'] as $season => $centsKey) {
            $insertRule->execute([
                'type' => 'package',
                'code' => $package['id'],
                'name' => $package['id'] . '_' . $season,
                'season' => $season,
                'checkin_weekday' => (int) $package['checkinWeekday'],
                'checkout_weekday' => (int) $package['checkoutWeekday'],
                'nights' => (int) $package['nights'],
                'min_nights' => (int) $package['nights'],
                'max_nights' => (int) $package['nights'],
                'start_date' => null,
                'end_date' => null,
                'days_of_week' => null,
                'amount_cents' => (int) $package[$centsKey],
                'amount_percent' => null,
                'calculation' => 'fixed_per_stay',
                'priority' => 100,
                'enabled' => $enabled,
                'extra_guest_threshold' => null,
                'extra_guest_cents' => null,
                'notes' => 'Published site package',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    $fees = [
        ['code' => 'cleaning', 'name' => 'Eindschoonmaak', 'cents' => (int) $json['fees']['cleaningCents'], 'calc' => 'fixed_per_stay', 'prio' => 10],
        ['code' => 'tourist_tax', 'name' => 'Toeristenbelasting', 'cents' => (int) $json['fees']['touristTaxPerPersonPerNightCents'], 'calc' => 'per_person_per_night', 'prio' => 10],
        ['code' => 'sunday_evening', 'name' => 'Zondagavond extra', 'cents' => (int) $json['fees']['sundayEveningExtraCents'], 'calc' => 'optional_fixed', 'prio' => 10],
        ['code' => 'security_deposit', 'name' => 'Huurwaarborg', 'cents' => (int) $json['fees']['securityDepositCents'], 'calc' => 'refundable', 'prio' => 10],
    ];
    foreach ($fees as $fee) {
        $insertRule->execute([
            'type' => 'fee',
            'code' => $fee['code'],
            'name' => $fee['name'],
            'season' => null,
            'checkin_weekday' => null,
            'checkout_weekday' => null,
            'nights' => null,
            'min_nights' => null,
            'max_nights' => null,
            'start_date' => null,
            'end_date' => null,
            'days_of_week' => null,
            'amount_cents' => $fee['cents'],
            'amount_percent' => null,
            'calculation' => $fee['calc'],
            'priority' => $fee['prio'],
            'enabled' => $enabled,
            'extra_guest_threshold' => null,
            'extra_guest_cents' => null,
            'notes' => 'Published site fee',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $db->query(
        'INSERT INTO calendar_connections (
            provider, name, type, url, url_env_key, enabled,
            last_sync_attempt_at, last_successful_sync_at, last_error, imported_event_count,
            created_at, updated_at
        ) VALUES (
            :provider, :name, :type, :url, :url_env_key, :enabled,
            NULL, NULL, NULL, 0, :created_at, :updated_at
        )',
        [
            'provider' => 'airbnb',
            'name' => 'Airbnb',
            'type' => 'ical_import',
            'url' => null,
            'url_env_key' => 'AIRBNB_ICAL_URL',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    // Booking.com is addable later from admin (same table, no code change). Not seeded.
};
