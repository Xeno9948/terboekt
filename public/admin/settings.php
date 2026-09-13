<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

[$app, $user] = admin_require();
$s = $app->settings();

if (admin_is_post()) {
    if (!admin_verify_csrf()) {
        admin_set_flash('error', 'Beveiligingscontrole mislukt.');
        admin_redirect('settings.php');
    }
    try {
        $keys = [
            'property_name',
            'property_address',
            'contact_email',
            'max_guests',
            'bedrooms',
            'checkin_from',
            'checkout_before',
            'timezone',
            'currency',
            'deposit_percentage',
            'deposit_deadline_days',
            'request_expiry_days',
            'bank_account_holder',
            'bank_iban',
            'bank_bic',
            'bank_name',
            'house_rules_url',
            'privacy_url',
            'cancellation_url',
            'terms_version',
        ];
        $email = trim((string) ($_POST['contact_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ongeldig contactadres.');
        }
        $maxGuests = (int) ($_POST['max_guests'] ?? 8);
        if ($maxGuests < 1 || $maxGuests > 8) {
            throw new InvalidArgumentException('Maximum gasten blijft tussen 1 en 8 (capaciteit van het huis).');
        }
        $percent = (int) ($_POST['deposit_percentage'] ?? 30);
        if ($percent < 1 || $percent > 100) {
            throw new InvalidArgumentException('Voorschotpercentage moet tussen 1 en 100 liggen.');
        }
        foreach ($keys as $key) {
            $value = trim((string) ($_POST[$key] ?? ''));
            if (in_array($key, ['max_guests', 'bedrooms', 'deposit_percentage', 'deposit_deadline_days', 'request_expiry_days'], true)) {
                $value = $value === '' ? '' : (string) (int) $value;
            }
            $app->settings()->upsert($key, $value === '' ? ($key === 'bank_iban' || $key === 'bank_bic' || $key === 'bank_name' || $key === 'bank_account_holder' || $key === 'privacy_url' || $key === 'cancellation_url' || $key === 'terms_version' ? '' : $value) : $value);
        }
        admin_set_flash('success', 'Instellingen opgeslagen. Bankvelden blijven leeg tot u ze invult.');
    } catch (Throwable $e) {
        admin_handle_action_error($e);
    }
    admin_redirect('settings.php');
}

$val = static function (Terboekt\Repositories\PropertySettingsRepository $s, string $key, string $default = '') : string {
    return (string) ($s->get($key, $default) ?? $default);
};

admin_layout_start('Instellingen', 'settings', $user);
?>
<form class="admin-form" method="post">
    <?= admin_csrf_field() ?>
    <p class="admin-help">Naam, adres, e-mail, aantal gasten, slaapkamers en check-in/uit verschijnen op de website (header, footer, prijzen, huisreglement). Voorschotpercentage staat in de boekingsmail.</p>
    <section class="admin-card">
        <h2>Woning</h2>
        <div class="form-row">
            <div class="form-group">
                <label for="property_name">Naam</label>
                <input type="text" id="property_name" name="property_name" value="<?= h($val($s, 'property_name', 'Home Terboekt')) ?>">
            </div>
            <div class="form-group">
                <label for="property_address">Adres</label>
                <input type="text" id="property_address" name="property_address" value="<?= h($val($s, 'property_address', 'Terboekt 28, 3600 Genk')) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="contact_email">Contact e-mail</label>
                <input type="email" id="contact_email" name="contact_email" value="<?= h($val($s, 'contact_email', 'info@hometerboekt.be')) ?>">
            </div>
            <div class="form-group">
                <label for="max_guests">Maximum gasten</label>
                <input type="number" id="max_guests" name="max_guests" min="1" max="8" value="<?= h($val($s, 'max_guests', '8')) ?>">
            </div>
            <div class="form-group">
                <label for="bedrooms">Slaapkamers</label>
                <input type="number" id="bedrooms" name="bedrooms" min="1" max="8" value="<?= h($val($s, 'bedrooms', '4')) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="checkin_from">Check-in vanaf</label>
                <input type="text" id="checkin_from" name="checkin_from" value="<?= h($val($s, 'checkin_from', '16:00')) ?>">
            </div>
            <div class="form-group">
                <label for="checkout_before">Check-out vóór</label>
                <input type="text" id="checkout_before" name="checkout_before" value="<?= h($val($s, 'checkout_before', '10:00')) ?>">
            </div>
            <div class="form-group">
                <label for="timezone">Tijdzone</label>
                <input type="text" id="timezone" name="timezone" value="<?= h($val($s, 'timezone', 'Europe/Brussels')) ?>">
            </div>
            <div class="form-group">
                <label for="currency">Munteenheid</label>
                <input type="text" id="currency" name="currency" maxlength="3" value="<?= h($val($s, 'currency', 'EUR')) ?>">
            </div>
        </div>
    </section>

    <section class="admin-card">
        <h2>Voorschot</h2>
        <div class="form-row">
            <div class="form-group">
                <label for="deposit_percentage">Voorschot (%)</label>
                <input type="number" id="deposit_percentage" name="deposit_percentage" min="1" max="100" value="<?= h($val($s, 'deposit_percentage', '30')) ?>">
            </div>
            <div class="form-group">
                <label for="deposit_deadline_days">Dagen tot deadline</label>
                <input type="number" id="deposit_deadline_days" name="deposit_deadline_days" min="1" max="60" value="<?= h($val($s, 'deposit_deadline_days', '7')) ?>">
            </div>
            <div class="form-group">
                <label for="request_expiry_days">Aanvraag vervalt na (dagen)</label>
                <input type="number" id="request_expiry_days" name="request_expiry_days" min="1" max="60" value="<?= h($val($s, 'request_expiry_days', '7')) ?>">
            </div>
        </div>
    </section>

    <section class="admin-card">
        <h2>Bankgegevens</h2>
        <p class="admin-help">Leeg laten tot u echte gegevens hebt. We vullen geen IBAN in.</p>
        <div class="form-row">
            <div class="form-group">
                <label for="bank_account_holder">Rekeninghouder</label>
                <input type="text" id="bank_account_holder" name="bank_account_holder" value="<?= h($val($s, 'bank_account_holder')) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="bank_iban">IBAN</label>
                <input type="text" id="bank_iban" name="bank_iban" value="<?= h($val($s, 'bank_iban')) ?>" autocomplete="off" placeholder="">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="bank_bic">BIC</label>
                <input type="text" id="bank_bic" name="bank_bic" value="<?= h($val($s, 'bank_bic')) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="bank_name">Bank</label>
                <input type="text" id="bank_name" name="bank_name" value="<?= h($val($s, 'bank_name')) ?>">
            </div>
        </div>
    </section>

    <section class="admin-card">
        <h2>Voorwaarden</h2>
        <div class="form-group">
            <label for="house_rules_url">Huur- en boekingsvoorwaarden (URL)</label>
            <input type="text" id="house_rules_url" name="house_rules_url" value="<?= h($val($s, 'house_rules_url')) ?>">
        </div>
        <div class="form-group">
            <label for="privacy_url">Privacy (URL)</label>
            <input type="url" id="privacy_url" name="privacy_url" value="<?= h($val($s, 'privacy_url')) ?>" placeholder="https://">
        </div>
        <div class="form-group">
            <label for="cancellation_url">Annulatievoorwaarden (URL)</label>
            <input type="url" id="cancellation_url" name="cancellation_url" value="<?= h($val($s, 'cancellation_url')) ?>" placeholder="https://">
        </div>
        <div class="form-group">
            <label for="terms_version">Versie voorwaarden</label>
            <input type="text" id="terms_version" name="terms_version" value="<?= h($val($s, 'terms_version')) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Bewaar instellingen</button>
    </section>
</form>
<?php
admin_layout_end();
