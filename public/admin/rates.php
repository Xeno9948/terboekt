<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

use Terboekt\DateRange;

[$app, $user] = admin_require();

if (admin_is_post()) {
    if (!admin_verify_csrf()) {
        admin_set_flash('error', 'Beveiligingscontrole mislukt.');
        admin_redirect('rates.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_packages') {
            $ids = $_POST['id'] ?? [];
            $amounts = $_POST['amount'] ?? [];
            $enabled = $_POST['enabled'] ?? [];
            if (is_array($ids)) {
                foreach ($ids as $i => $idRaw) {
                    $id = (int) $idRaw;
                    $cents = admin_parse_euros((string) ($amounts[$i] ?? ''));
                    if ($cents === null) {
                        continue;
                    }
                    $on = isset($enabled[$id]) ? 1 : 0;
                    $app->db->query(
                        'UPDATE rate_rules SET amount_cents = :c, enabled = :e, updated_at = :u WHERE id = :id AND type = :t',
                        ['c' => $cents, 'e' => $on, 'u' => $app->db->now(), 'id' => $id, 't' => 'package']
                    );
                }
            }
            admin_set_flash('success', 'Pakketprijzen opgeslagen.');
        } elseif ($action === 'save_fees') {
            $ids = $_POST['id'] ?? [];
            $amounts = $_POST['amount'] ?? [];
            $map = [
                'cleaning' => 'cleaning_fee_cents',
                'tourist_tax' => 'tourist_tax_per_person_per_night_cents',
                'sunday_evening' => 'sunday_evening_extra_cents',
                'security_deposit' => 'security_deposit_cents',
            ];
            if (is_array($ids)) {
                foreach ($ids as $i => $idRaw) {
                    $id = (int) $idRaw;
                    $cents = admin_parse_euros((string) ($amounts[$i] ?? ''));
                    if ($cents === null) {
                        continue;
                    }
                    $row = $app->db->fetchOne('SELECT * FROM rate_rules WHERE id = :id AND type = :t', ['id' => $id, 't' => 'fee']);
                    if ($row === null) {
                        continue;
                    }
                    $app->db->query(
                        'UPDATE rate_rules SET amount_cents = :c, updated_at = :u WHERE id = :id',
                        ['c' => $cents, 'u' => $app->db->now(), 'id' => $id]
                    );
                    $setting = $map[(string) $row['code']] ?? null;
                    if ($setting) {
                        $app->settings()->upsert($setting, (string) $cents);
                    }
                }
            }
            admin_set_flash('success', 'Toeslagen opgeslagen.');
        } elseif ($action === 'save_nightly') {
            $raw = trim((string) ($_POST['default_nightly'] ?? ''));
            $cents = $raw === '' ? null : admin_parse_euros($raw);
            $app->settings()->upsert('default_nightly_cents', $cents === null ? '' : (string) $cents);
            admin_set_flash('success', 'Standaard nachtprijs opgeslagen. Leeg = niet gebruikt (geen verzonnen tarief).');
        } elseif ($action === 'save_minmax') {
            $min = trim((string) ($_POST['min_nights'] ?? ''));
            $maxN = trim((string) ($_POST['max_nights'] ?? ''));
            $existing = $app->db->fetchOne("SELECT * FROM rate_rules WHERE type = 'min_stay' ORDER BY id LIMIT 1");
            $minVal = $min === '' ? null : (int) $min;
            $maxVal = $maxN === '' ? null : (int) $maxN;
            if ($existing) {
                $app->db->query(
                    'UPDATE rate_rules SET min_nights = :min, max_nights = :max, enabled = :e, updated_at = :u WHERE id = :id',
                    [
                        'min' => $minVal,
                        'max' => $maxVal,
                        'e' => ($minVal !== null || $maxVal !== null) ? 1 : 0,
                        'u' => $app->db->now(),
                        'id' => (int) $existing['id'],
                    ]
                );
            } elseif ($minVal !== null || $maxVal !== null) {
                $app->rates()->insert([
                    'type' => 'min_stay',
                    'code' => 'stay_window',
                    'name' => 'Minimum/maximum verblijf',
                    'season' => null,
                    'checkin_weekday' => null,
                    'checkout_weekday' => null,
                    'nights' => null,
                    'min_nights' => $minVal,
                    'max_nights' => $maxVal,
                    'start_date' => null,
                    'end_date' => null,
                    'days_of_week' => null,
                    'amount_cents' => null,
                    'amount_percent' => null,
                    'calculation' => null,
                    'priority' => 50,
                    'enabled' => 1,
                    'extra_guest_threshold' => null,
                    'extra_guest_cents' => null,
                    'notes' => 'Admin stay window',
                ]);
            }
            admin_set_flash('success', 'Minimum- en maximumverblijf opgeslagen.');
        } elseif ($action === 'save_extra_guest') {
            $threshold = trim((string) ($_POST['extra_guest_threshold'] ?? ''));
            $amount = trim((string) ($_POST['extra_guest_cents'] ?? ''));
            $existing = $app->db->fetchOne("SELECT * FROM rate_rules WHERE code = 'extra_guest' ORDER BY id LIMIT 1");
            $th = $threshold === '' ? null : (int) $threshold;
            $cents = $amount === '' ? null : admin_parse_euros($amount);
            $data = [
                'extra_guest_threshold' => $th,
                'extra_guest_cents' => $cents,
                'enabled' => ($th !== null && $cents !== null) ? 1 : 0,
                'updated_at' => $app->db->now(),
            ];
            if ($existing) {
                $app->db->query(
                    'UPDATE rate_rules SET extra_guest_threshold = :t, extra_guest_cents = :c, enabled = :e, updated_at = :u WHERE id = :id',
                    ['t' => $th, 'c' => $cents, 'e' => $data['enabled'], 'u' => $data['updated_at'], 'id' => (int) $existing['id']]
                );
            } elseif ($th !== null && $cents !== null) {
                $app->rates()->insert([
                    'type' => 'fee',
                    'code' => 'extra_guest',
                    'name' => 'Extra gast',
                    'season' => null,
                    'checkin_weekday' => null,
                    'checkout_weekday' => null,
                    'nights' => null,
                    'min_nights' => null,
                    'max_nights' => null,
                    'start_date' => null,
                    'end_date' => null,
                    'days_of_week' => null,
                    'amount_cents' => 0,
                    'amount_percent' => null,
                    'calculation' => 'extra_guest',
                    'priority' => 20,
                    'enabled' => 1,
                    'extra_guest_threshold' => $th,
                    'extra_guest_cents' => $cents,
                    'notes' => 'Admin extra guest',
                ]);
            }
            admin_set_flash('success', 'Extra-gasttarief opgeslagen. Lege velden blijven ongebruikt.');
        } elseif ($action === 'add_override') {
            $start = trim((string) ($_POST['start_date'] ?? ''));
            $end = trim((string) ($_POST['end_date'] ?? ''));
            DateRange::of($start, $end);
            $cents = admin_parse_euros((string) ($_POST['amount'] ?? ''));
            if ($cents === null) {
                throw new InvalidArgumentException('Vul een nachtprijs in.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $app->rates()->insert([
                'type' => 'date_override',
                'code' => 'override_' . $start,
                'name' => $name !== '' ? $name : ('Override ' . $start),
                'season' => null,
                'checkin_weekday' => null,
                'checkout_weekday' => null,
                'nights' => null,
                'min_nights' => null,
                'max_nights' => null,
                'start_date' => $start,
                'end_date' => $end,
                'days_of_week' => null,
                'amount_cents' => $cents,
                'amount_percent' => null,
                'calculation' => 'per_night',
                'priority' => 200,
                'enabled' => 1,
                'extra_guest_threshold' => null,
                'extra_guest_cents' => null,
                'notes' => 'Admin date override',
            ]);
            admin_set_flash('success', 'Datumoverride toegevoegd.');
        } elseif ($action === 'add_seasonal') {
            $start = trim((string) ($_POST['start_date'] ?? ''));
            $end = trim((string) ($_POST['end_date'] ?? ''));
            DateRange::of($start, $end);
            $cents = admin_parse_euros((string) ($_POST['amount'] ?? ''));
            if ($cents === null) {
                throw new InvalidArgumentException('Vul een nachtprijs in.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $app->rates()->insert([
                'type' => 'seasonal',
                'code' => 'seasonal_' . $start,
                'name' => $name !== '' ? $name : ('Seizoen ' . $start),
                'season' => trim((string) ($_POST['season'] ?? '')) ?: null,
                'checkin_weekday' => null,
                'checkout_weekday' => null,
                'nights' => null,
                'min_nights' => null,
                'max_nights' => null,
                'start_date' => $start,
                'end_date' => $end,
                'days_of_week' => null,
                'amount_cents' => $cents,
                'amount_percent' => null,
                'calculation' => 'per_night',
                'priority' => 150,
                'enabled' => 1,
                'extra_guest_threshold' => null,
                'extra_guest_cents' => null,
                'notes' => 'Admin seasonal range',
            ]);
            admin_set_flash('success', 'Seizoensregel toegevoegd.');
        } elseif ($action === 'add_weekend') {
            $cents = admin_parse_euros((string) ($_POST['amount'] ?? ''));
            if ($cents === null) {
                throw new InvalidArgumentException('Vul een toeslag in. We verzinnen geen weekendprijs.');
            }
            $existing = $app->db->fetchOne("SELECT id FROM rate_rules WHERE type = 'weekend' LIMIT 1");
            if ($existing) {
                $app->db->query(
                    'UPDATE rate_rules SET amount_cents = :c, enabled = 1, updated_at = :u WHERE id = :id',
                    ['c' => $cents, 'u' => $app->db->now(), 'id' => (int) $existing['id']]
                );
            } else {
                $app->rates()->insert([
                    'type' => 'weekend',
                    'code' => 'weekend_modifier',
                    'name' => 'Weekendtoeslag (nachtprijs)',
                    'season' => null,
                    'checkin_weekday' => null,
                    'checkout_weekday' => null,
                    'nights' => null,
                    'min_nights' => null,
                    'max_nights' => null,
                    'start_date' => null,
                    'end_date' => null,
                    'days_of_week' => json_encode([5, 6]),
                    'amount_cents' => $cents,
                    'amount_percent' => null,
                    'calculation' => 'per_night',
                    'priority' => 80,
                    'enabled' => 1,
                    'extra_guest_threshold' => null,
                    'extra_guest_cents' => null,
                    'notes' => 'Added on top of nightly rates (Fri/Sat)',
                ]);
            }
            admin_set_flash('success', 'Weekendtoeslag op nachtprijs opgeslagen.');
        } elseif ($action === 'add_discount') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $centsRaw = trim((string) ($_POST['amount'] ?? ''));
            $percentRaw = trim((string) ($_POST['percent'] ?? ''));
            $cents = $centsRaw === '' ? null : admin_parse_euros($centsRaw);
            $percent = $percentRaw === '' ? null : (int) $percentRaw;
            if ($name === '' || ($cents === null && $percent === null)) {
                throw new InvalidArgumentException('Geef een naam en een bedrag of percentage.');
            }
            $app->rates()->insert([
                'type' => 'discount',
                'code' => 'discount_' . substr(bin2hex(random_bytes(3)), 0, 6),
                'name' => $name,
                'season' => null,
                'checkin_weekday' => null,
                'checkout_weekday' => null,
                'nights' => null,
                'min_nights' => null,
                'max_nights' => null,
                'start_date' => null,
                'end_date' => null,
                'days_of_week' => null,
                'amount_cents' => $cents,
                'amount_percent' => $percent,
                'calculation' => $percent !== null ? 'percent' : 'fixed',
                'priority' => 40,
                'enabled' => 1,
                'extra_guest_threshold' => null,
                'extra_guest_cents' => null,
                'notes' => 'Admin discount',
            ]);
            admin_set_flash('success', 'Korting toegevoegd.');
        } elseif ($action === 'delete_rule') {
            $id = (int) ($_POST['id'] ?? 0);
            $app->db->query(
                "DELETE FROM rate_rules WHERE id = :id AND type IN ('date_override','seasonal','discount','weekend')",
                ['id' => $id]
            );
            admin_set_flash('success', 'Regel verwijderd.');
        } elseif ($action === 'update_rule') {
            $id = (int) ($_POST['id'] ?? 0);
            $cents = admin_parse_euros((string) ($_POST['amount'] ?? ''));
            $enabled = empty($_POST['enabled']) ? 0 : 1;
            if ($cents === null) {
                throw new InvalidArgumentException('Vul een bedrag in.');
            }
            $app->db->query(
                'UPDATE rate_rules SET amount_cents = :c, enabled = :e, updated_at = :u WHERE id = :id',
                ['c' => $cents, 'e' => $enabled, 'u' => $app->db->now(), 'id' => $id]
            );
            admin_set_flash('success', 'Regel bijgewerkt.');
        }
    } catch (Throwable $e) {
        admin_handle_action_error($e);
    }
    admin_redirect('rates.php');
}

$packages = $app->db->fetchAll("SELECT * FROM rate_rules WHERE type = 'package' ORDER BY nights, season");
$fees = $app->db->fetchAll("SELECT * FROM rate_rules WHERE type = 'fee' ORDER BY id");
$overrides = $app->db->fetchAll("SELECT * FROM rate_rules WHERE type = 'date_override' ORDER BY start_date");
$seasonal = $app->db->fetchAll("SELECT * FROM rate_rules WHERE type = 'seasonal' ORDER BY start_date");
$weekend = $app->db->fetchOne("SELECT * FROM rate_rules WHERE type = 'weekend' ORDER BY id LIMIT 1");
$discounts = $app->db->fetchAll("SELECT * FROM rate_rules WHERE type = 'discount' ORDER BY id");
$minStay = $app->db->fetchOne("SELECT * FROM rate_rules WHERE type = 'min_stay' ORDER BY id LIMIT 1");
$extraGuest = $app->db->fetchOne("SELECT * FROM rate_rules WHERE code = 'extra_guest' ORDER BY id LIMIT 1");
$nightly = $app->settings()->get('default_nightly_cents');
$published = $app->publishedRates()->publicPayload();

admin_layout_start('Prijzen', 'rates', $user);
?>
<p class="admin-help">Wijzigingen hier verschijnen meteen op de website (prijstabel, toeslagen, boekingspagina) én in de live offerte. Alleen bestaande pakketten, toeslagen en wat u zelf toevoegt. We verzinnen geen nachtprijs of IBAN. Oktober blijft onbepaald tot u een datumregel zet.</p>

<section class="admin-card">
    <h2>Pakketten</h2>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="save_packages">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Pakket</th><th>Seizoen</th><th>Nachten</th><th>Prijs (€)</th><th>Actief</th></tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $row): ?>
                    <tr>
                        <td>
                            <?= h(admin_package_label((string) $row['code'])) ?>
                            <input type="hidden" name="id[]" value="<?= (int) $row['id'] ?>">
                        </td>
                        <td><?= h(admin_season_label(isset($row['season']) ? (string) $row['season'] : null)) ?></td>
                        <td><?= (int) $row['nights'] ?></td>
                        <td><input type="text" name="amount[]" inputmode="decimal" value="<?= h(admin_euro_value(isset($row['amount_cents']) ? (int) $row['amount_cents'] : 0)) ?>" required></td>
                        <td><input type="checkbox" name="enabled[<?= (int) $row['id'] ?>]" value="1"<?= (int) $row['enabled'] ? ' checked' : '' ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:0.75rem;">Bewaar pakketten</button>
    </form>
</section>

<section class="admin-card">
    <h2>Toeslagen en belasting</h2>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="save_fees">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Onderdeel</th><th>Berekening</th><th>Bedrag (€)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($fees as $row):
                    if ((string) $row['code'] === 'extra_guest') {
                        continue;
                    }
                    ?>
                    <tr>
                        <td>
                            <?= h((string) $row['name']) ?>
                            <input type="hidden" name="id[]" value="<?= (int) $row['id'] ?>">
                        </td>
                        <td><?= h((string) ($row['calculation'] ?? '')) ?></td>
                        <td><input type="text" name="amount[]" inputmode="decimal" value="<?= h(admin_euro_value(isset($row['amount_cents']) ? (int) $row['amount_cents'] : 0)) ?>" required></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="admin-help">Toeristenbelasting is per persoon per nacht. Huurwaarborg is terugbetaalbaar en zit niet in het voorschot.</p>
        <button class="btn btn-primary" type="submit">Bewaar toeslagen</button>
    </form>
</section>

<div class="admin-grid-2">
    <section class="admin-card">
        <h2>Standaard nachtprijs</h2>
        <p class="admin-help">Alleen gebruikt als een verblijf geen pakket is. Leeg laten als u geen nachtprijs hebt.</p>
        <form class="admin-form" method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="save_nightly">
            <div class="form-group">
                <label for="default_nightly">Bedrag per nacht (€)</label>
                <input type="text" id="default_nightly" name="default_nightly" inputmode="decimal" value="<?= h($nightly !== null && $nightly !== '' ? admin_euro_value((int) $nightly) : '') ?>" placeholder="leeg = niet ingesteld">
            </div>
            <button class="btn btn-secondary" type="submit">Bewaar nachtprijs</button>
        </form>
    </section>
    <section class="admin-card">
        <h2>Weekendtoeslag (nachtprijs)</h2>
        <p class="admin-help">Komt bovenop de nachtprijs (vr/za). Los van het weekendpakket hierboven.</p>
        <form class="admin-form" method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="add_weekend">
            <div class="form-group">
                <label for="weekend_amount">Toeslag per nacht (€)</label>
                <input type="text" id="weekend_amount" name="amount" inputmode="decimal" required value="<?= h($weekend && $weekend['amount_cents'] !== null ? admin_euro_value((int) $weekend['amount_cents']) : '') ?>">
            </div>
            <button class="btn btn-secondary" type="submit">Bewaar weekendtoeslag</button>
        </form>
        <?php if ($weekend): ?>
            <form method="post" data-confirm="Weekendtoeslag verwijderen?" style="margin-top:0.5rem;">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="delete_rule">
                <input type="hidden" name="id" value="<?= (int) $weekend['id'] ?>">
                <button class="btn btn-outline" type="submit">Verwijder toeslag</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<div class="admin-grid-2">
    <section class="admin-card">
        <h2>Extra gast</h2>
        <form class="admin-form" method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="save_extra_guest">
            <div class="form-group">
                <label for="extra_guest_threshold">Vanaf hoeveelste gast</label>
                <input type="number" id="extra_guest_threshold" name="extra_guest_threshold" min="1" max="8" value="<?= h((string) ($extraGuest['extra_guest_threshold'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="extra_guest_cents">Per extra gast per nacht (€)</label>
                <input type="text" id="extra_guest_cents" name="extra_guest_cents" inputmode="decimal" value="<?= h(isset($extraGuest['extra_guest_cents']) && $extraGuest['extra_guest_cents'] !== null ? admin_euro_value((int) $extraGuest['extra_guest_cents']) : '') ?>">
            </div>
            <button class="btn btn-secondary" type="submit">Bewaar extra gast</button>
        </form>
    </section>
    <section class="admin-card">
        <h2>Minimum / maximum verblijf</h2>
        <form class="admin-form" method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="save_minmax">
            <div class="form-row">
                <div class="form-group">
                    <label for="min_nights">Minimum nachten</label>
                    <input type="number" id="min_nights" name="min_nights" min="1" max="30" value="<?= h((string) ($minStay['min_nights'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label for="max_nights">Maximum nachten</label>
                    <input type="number" id="max_nights" name="max_nights" min="1" max="90" value="<?= h((string) ($minStay['max_nights'] ?? '')) ?>">
                </div>
            </div>
            <button class="btn btn-secondary" type="submit">Bewaar verblijfsduur</button>
        </form>
    </section>
</div>

<section class="admin-card">
    <h2>Seizoen (site)</h2>
    <p>Hoogseizoen: maanden <?= h(implode(', ', array_map('strval', $published['seasons']['highMonths'] ?? [4, 5, 6, 7, 8, 9]))) ?> · Laagseizoen: <?= h(implode(', ', array_map('strval', $published['seasons']['lowMonths'] ?? []))) ?> · Onbepaald: oktober. Schoolvakanties staan niet in de data.</p>
    <h3 style="margin-top:1rem;">Seizoensperiode (nachtprijs)</h3>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="add_seasonal">
        <div class="form-row">
            <div class="form-group">
                <label for="s_name">Naam</label>
                <input type="text" id="s_name" name="name" placeholder="bv. Kerst">
            </div>
            <div class="form-group">
                <label for="s_start">Van</label>
                <input type="date" id="s_start" name="start_date" required>
            </div>
            <div class="form-group">
                <label for="s_end">Tot</label>
                <input type="date" id="s_end" name="end_date" required>
            </div>
            <div class="form-group">
                <label for="s_amount">Nachtprijs (€)</label>
                <input type="text" id="s_amount" name="amount" inputmode="decimal" required>
            </div>
        </div>
        <button class="btn btn-secondary" type="submit">Voeg seizoensregel toe</button>
    </form>
    <?php if ($seasonal !== []): ?>
        <div class="admin-table-wrap" style="margin-top:0.75rem;">
            <table class="admin-table">
                <thead><tr><th>Naam</th><th>Periode</th><th>Prijs</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($seasonal as $row): ?>
                    <tr>
                        <td><?= h((string) $row['name']) ?></td>
                        <td><?= h(admin_format_date((string) $row['start_date'])) ?> – <?= h(admin_format_date((string) $row['end_date'])) ?></td>
                        <td><?= h(admin_money((int) $row['amount_cents'])) ?></td>
                        <td>
                            <form method="post" data-confirm="Deze seizoensregel verwijderen?">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="delete_rule">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-danger" type="submit">Verwijder</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card">
    <h2>Datumoverrides</h2>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="add_override">
        <div class="form-row">
            <div class="form-group">
                <label for="o_name">Naam</label>
                <input type="text" id="o_name" name="name">
            </div>
            <div class="form-group">
                <label for="o_start">Van</label>
                <input type="date" id="o_start" name="start_date" required>
            </div>
            <div class="form-group">
                <label for="o_end">Tot</label>
                <input type="date" id="o_end" name="end_date" required>
            </div>
            <div class="form-group">
                <label for="o_amount">Nachtprijs (€)</label>
                <input type="text" id="o_amount" name="amount" inputmode="decimal" required>
            </div>
        </div>
        <button class="btn btn-secondary" type="submit">Voeg override toe</button>
    </form>
    <?php if ($overrides === []): ?>
        <p class="admin-empty">Nog geen datumoverrides.</p>
    <?php else: ?>
        <div class="admin-table-wrap" style="margin-top:0.75rem;">
            <table class="admin-table">
                <thead><tr><th>Naam</th><th>Periode</th><th>Prijs</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($overrides as $row): ?>
                    <tr>
                        <td><?= h((string) $row['name']) ?></td>
                        <td><?= h(admin_format_date((string) $row['start_date'])) ?> – <?= h(admin_format_date((string) $row['end_date'])) ?></td>
                        <td><?= h(admin_money((int) $row['amount_cents'])) ?></td>
                        <td>
                            <form method="post" data-confirm="Override verwijderen?">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="delete_rule">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-danger" type="submit">Verwijder</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card">
    <h2>Kortingen</h2>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="add_discount">
        <div class="form-row">
            <div class="form-group">
                <label for="d_name">Naam</label>
                <input type="text" id="d_name" name="name" required>
            </div>
            <div class="form-group">
                <label for="d_amount">Vast bedrag (€)</label>
                <input type="text" id="d_amount" name="amount" inputmode="decimal">
            </div>
            <div class="form-group">
                <label for="d_percent">Of percentage</label>
                <input type="number" id="d_percent" name="percent" min="1" max="100">
            </div>
        </div>
        <button class="btn btn-secondary" type="submit">Voeg korting toe</button>
    </form>
    <?php if ($discounts !== []): ?>
        <ul class="admin-list">
            <?php foreach ($discounts as $row): ?>
                <li>
                    <?= h((string) $row['name']) ?>
                    · <?= $row['amount_cents'] !== null ? h(admin_money((int) $row['amount_cents'])) : ((int) $row['amount_percent'] . '%') ?>
                    <form method="post" data-confirm="Korting verwijderen?" style="display:inline;">
                        <?= admin_csrf_field() ?>
                        <input type="hidden" name="action" value="delete_rule">
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                        <button class="btn btn-outline" type="submit">Verwijder</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
