<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

[$app, $user] = admin_require();

if (admin_is_post()) {
    if (!admin_verify_csrf()) {
        admin_set_flash('error', 'Beveiligingscontrole mislukt.');
        admin_redirect('calendars.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'refresh_all') {
            $app->calendarSync()->refreshAllEnabledCalendars();
            admin_set_flash('success', 'Alle ingeschakelde kalenders zijn vernieuwd.');
        } elseif ($action === 'refresh_one') {
            $id = (int) ($_POST['id'] ?? 0);
            $app->calendarSync()->refreshCalendarConnection($id);
            admin_set_flash('success', 'Kalender vernieuwd.');
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $app->calendars()->findById($id);
            if ($row === null) {
                throw new InvalidArgumentException('Koppeling niet gevonden.');
            }
            $app->calendars()->update($id, ['enabled' => (int) $row['enabled'] ? 0 : 1]);
            admin_set_flash('success', (int) $row['enabled'] ? 'Koppeling uitgeschakeld.' : 'Koppeling ingeschakeld.');
        } elseif ($action === 'save_url') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $app->calendars()->findById($id);
            if ($row === null) {
                throw new InvalidArgumentException('Koppeling niet gevonden.');
            }
            if (!empty($row['url_env_key'])) {
                throw new InvalidArgumentException('Deze feed komt uit de serverconfiguratie en kan hier niet overschreven worden.');
            }
            $url = trim((string) ($_POST['url'] ?? ''));
            $app->calendars()->update($id, ['url' => $url !== '' ? $url : null]);
            admin_set_flash('success', 'iCal-URL opgeslagen.');
        } elseif ($action === 'add') {
            $name = trim((string) ($_POST['name'] ?? 'Booking.com'));
            $provider = trim((string) ($_POST['provider'] ?? 'booking_com'));
            $url = trim((string) ($_POST['url'] ?? ''));
            $enabled = empty($_POST['enabled']) ? 0 : 1;
            if (!in_array($provider, ['booking_com', 'airbnb', 'other'], true)) {
                $provider = 'booking_com';
            }
            $exists = $app->db->fetchOne(
                'SELECT id FROM calendar_connections WHERE provider = :p AND name = :n',
                ['p' => $provider, 'n' => $name !== '' ? $name : 'Booking.com']
            );
            if ($exists) {
                throw new InvalidArgumentException('Er bestaat al een koppeling met deze naam.');
            }
            $app->calendars()->insert([
                'provider' => $provider,
                'name' => $name !== '' ? $name : 'Booking.com',
                'type' => 'ical_import',
                'url' => $url !== '' ? $url : null,
                'url_env_key' => null,
                'enabled' => $enabled,
            ]);
            admin_set_flash('success', 'Koppeling toegevoegd. U kunt later een iCal-URL invullen.');
        } elseif ($action === 'rotate_export') {
            $app->icalExport()->rotateSecret();
            admin_set_flash('success', 'Export-token vernieuwd. Plak de nieuwe URL opnieuw in Airbnb.');
        } elseif ($action === 'save_public_url') {
            $site = trim((string) ($_POST['public_site_url'] ?? ''));
            if ($site !== '' && !preg_match('#^https://#i', $site)) {
                throw new InvalidArgumentException('De publieke URL moet met https:// beginnen (Airbnb kan localhost niet ophalen).');
            }
            $app->settings()->upsert('public_site_url', $site !== '' ? rtrim($site, '/') : 'https://hometerboekt.be');
            admin_set_flash('success', 'Publieke site-URL bewaard.');
        }
    } catch (Throwable $e) {
        admin_handle_action_error($e);
    }
    admin_redirect('calendars.php');
}

$health = $app->calendarSync()->getCalendarHealth();
$connections = $app->calendars()->all();
$byId = [];
foreach ($connections as $c) {
    $byId[(int) $c['id']] = $c;
}

$exportSecret = $app->icalExport()->resolveSecret();
$publicBase = $app->settings()->get('public_site_url', 'https://hometerboekt.be') ?: 'https://hometerboekt.be';
$exportUrl = rtrim($publicBase, '/') . '/calendar/unavailable.ics?token=' . rawurlencode($exportSecret);
$localUrl = rtrim($app->config->appBaseUrl, '/') . '/calendar/unavailable.ics?token=' . rawurlencode($exportSecret);
$exportCount = $app->icalExport()->eventCount();

admin_layout_start('Kalenderkoppelingen', 'calendars', $user);
?>
<section class="admin-card">
    <h2>Feed voor Airbnb (wordt automatisch bijgewerkt)</h2>
    <p class="admin-help">
        Deze iCal is live: bij elke directe boeking, pending hold of handmatige blokkade verandert de feed meteen.
        Airbnb haalt die URL zelf periodiek op (meestal enkele keren per dag). Geïmporteerde Airbnb-dagen zitten
        er niet in, zodat er geen lus ontstaat. Geen gastnamen of prijzen.
    </p>
    <p><strong><?= (int) $exportCount ?></strong> geblokkeerde periodes staan nu in de export.</p>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="save_public_url">
        <label for="public_site_url">Publieke website (Airbnb moet deze URL kunnen bereiken)</label>
        <input type="url" id="public_site_url" name="public_site_url" value="<?= h($publicBase) ?>" required>
        <button class="btn btn-outline" type="submit" style="margin-top:0.4rem;">Bewaar site-URL</button>
    </form>
    <label for="export-url">Plak deze URL in Airbnb → Kalender → Beschikbaarheid synchroniseren / agenda importeren</label>
    <input id="export-url" type="text" readonly value="<?= h($exportUrl) ?>" onclick="this.select()">
    <div class="admin-actions" style="margin-top:0.6rem;">
        <button type="button" class="btn btn-primary" data-copy="export-url">Kopieer</button>
        <a class="btn btn-outline" href="<?= h($localUrl) ?>" target="_blank" rel="noopener">Voorbeeld openen</a>
    </div>
    <?php if ($localUrl !== $exportUrl): ?>
        <p class="admin-help">Lokaal testen: <?= h($localUrl) ?></p>
    <?php endif; ?>
    <form method="post" data-confirm="Nieuwe token maken? De oude URL in Airbnb stopt dan met werken." style="margin-top:1rem;">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="rotate_export">
        <button class="btn btn-secondary" type="submit">Vernieuw export-token</button>
    </form>
</section>
<section class="admin-card">
    <div class="admin-card-head">
        <h2>Gekoppelde agenda’s</h2>
        <form method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="refresh_all">
            <button class="btn btn-primary" type="submit">Vernieuw nu</button>
        </form>
    </div>
    <p class="admin-help">Mislukte sync laat bestaande blokkades staan. Airbnb-URL zit in de serverconfiguratie, niet in deze pagina.</p>
    <?php if (($health['connections'] ?? []) === []): ?>
        <p class="admin-empty">Nog geen koppelingen.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>Aanbieder</th>
                        <th>Aan</th>
                        <th>Laatste poging</th>
                        <th>Laatst gelukt</th>
                        <th>Events</th>
                        <th>Fout</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($health['connections'] as $row):
                    $full = $byId[(int) $row['id']] ?? null;
                    ?>
                    <tr>
                        <td><?= h((string) $row['name']) ?></td>
                        <td><?= h((string) $row['provider']) ?></td>
                        <td><?= !empty($row['enabled']) ? 'Ja' : 'Nee' ?></td>
                        <td><?= h(admin_format_dt(isset($row['last_sync_attempt_at']) ? (string) $row['last_sync_attempt_at'] : null)) ?></td>
                        <td><?= h(admin_format_dt(isset($row['last_successful_sync_at']) ? (string) $row['last_successful_sync_at'] : null)) ?></td>
                        <td><?= (int) ($row['imported_event_count'] ?? 0) ?></td>
                        <td><?= h((string) ($row['last_error'] ?: '—')) ?></td>
                        <td>
                            <div class="admin-actions">
                                <form method="post">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="refresh_one">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-outline" type="submit">Vernieuw</button>
                                </form>
                                <form method="post">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-secondary" type="submit"><?= !empty($row['enabled']) ? 'Zet uit' : 'Zet aan' ?></button>
                                </form>
                            </div>
                            <?php if ($full && empty($full['url_env_key'])): ?>
                                <form class="admin-form" method="post" style="margin-top:0.5rem;">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="save_url">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <label for="url-<?= (int) $row['id'] ?>">iCal-URL</label>
                                    <input type="url" id="url-<?= (int) $row['id'] ?>" name="url" value="<?= h((string) ($full['url'] ?? '')) ?>" placeholder="https://…">
                                    <button class="btn btn-outline" type="submit" style="margin-top:0.4rem;">Bewaar URL</button>
                                </form>
                            <?php elseif ($full && !empty($full['url_env_key'])): ?>
                                <p class="admin-help">URL via <?= h((string) $full['url_env_key']) ?><?= !empty($row['has_url']) ? ' (ingesteld)' : ' (nog leeg op de server)' ?>.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card">
    <h2>Booking.com later toevoegen</h2>
    <p class="admin-help">URL mag leeg blijven tot u de exportlink van Booking.com hebt.</p>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="provider" value="booking_com">
        <div class="form-row">
            <div class="form-group">
                <label for="name">Naam</label>
                <input type="text" id="name" name="name" value="Booking.com" required>
            </div>
            <div class="form-group">
                <label for="new_url">iCal-URL (optioneel)</label>
                <input type="url" id="new_url" name="url" placeholder="https://…">
            </div>
        </div>
        <label class="checkbox"><input type="checkbox" name="enabled" value="1" checked> Inschakelen</label>
        <div class="admin-actions" style="margin-top:0.75rem;">
            <button class="btn btn-primary" type="submit">Voeg koppeling toe</button>
        </div>
    </form>
</section>
<?php
admin_layout_end();
