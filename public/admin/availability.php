<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

use Terboekt\DateRange;

[$app, $user] = admin_require();

$manualSources = ['manual', 'owner', 'maintenance'];

if (admin_is_post()) {
    if (!admin_verify_csrf()) {
        admin_set_flash('error', 'Beveiligingscontrole mislukt.');
        admin_redirect('availability.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $start = trim((string) ($_POST['start'] ?? ''));
            $end = trim((string) ($_POST['end'] ?? ''));
            DateRange::of($start, $end);
            $source = (string) ($_POST['source'] ?? 'manual');
            if (!in_array($source, $manualSources, true)) {
                $source = 'manual';
            }
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $app->blocks()->insert([
                'start_date' => $start,
                'end_date' => $end,
                'source' => $source,
                'booking_id' => null,
                'calendar_connection_id' => null,
                'external_uid' => null,
                'notes' => $notes !== '' ? $notes : null,
            ]);
            admin_set_flash('success', 'Blokkade toegevoegd.');
        } elseif ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $app->blocks()->findById($id);
            if ($row === null || !in_array((string) $row['source'], $manualSources, true)) {
                throw new InvalidArgumentException('Deze blokkade kan hier niet gewijzigd worden.');
            }
            $start = trim((string) ($_POST['start'] ?? ''));
            $end = trim((string) ($_POST['end'] ?? ''));
            DateRange::of($start, $end);
            $source = (string) ($_POST['source'] ?? $row['source']);
            if (!in_array($source, $manualSources, true)) {
                $source = (string) $row['source'];
            }
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $app->db->query(
                'UPDATE availability_blocks SET start_date = :s, end_date = :e, source = :src, notes = :n WHERE id = :id',
                ['s' => $start, 'e' => $end, 'src' => $source, 'n' => $notes !== '' ? $notes : null, 'id' => $id]
            );
            admin_set_flash('success', 'Blokkade bijgewerkt.');
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $app->db->query(
                "DELETE FROM availability_blocks WHERE id = :id AND source IN ('manual','owner','maintenance')",
                ['id' => $id]
            );
            admin_set_flash('success', 'Blokkade verwijderd.');
        } elseif ($action === 'save_windows') {
            $lead = trim((string) ($_POST['booking_lead_time_days'] ?? ''));
            $advance = trim((string) ($_POST['booking_max_advance_days'] ?? ''));
            $app->settings()->upsert('booking_lead_time_days', $lead === '' ? null : (string) (int) $lead);
            $app->settings()->upsert('booking_max_advance_days', $advance === '' ? null : (string) (int) $advance);
            admin_set_flash('success', 'Boekingsvenster opgeslagen.');
        }
    } catch (Throwable $e) {
        admin_handle_action_error($e);
    }
    admin_redirect('availability.php');
}

$blocks = $app->db->fetchAll(
    "SELECT * FROM availability_blocks
     WHERE source IN ('manual','owner','maintenance')
     ORDER BY start_date DESC"
);
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId > 0 ? $app->blocks()->findById($editId) : null;
if ($edit && !in_array((string) $edit['source'], $manualSources, true)) {
    $edit = null;
}

admin_layout_start('Beschikbaarheid', 'availability', $user);
?>
<section class="admin-card">
    <h2><?= $edit ? 'Blokkade wijzigen' : 'Data blokkeren' ?></h2>
    <p class="admin-help">Gebruik dit voor sluiting, eigen verblijf of onderhoud. Vertrekdag blijft vrij voor de volgende gast.</p>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?>
            <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label for="start">Van</label>
                <input type="date" id="start" name="start" required value="<?= h((string) ($edit['start_date'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="end">Tot (vertrekdag, niet bezet)</label>
                <input type="date" id="end" name="end" required value="<?= h((string) ($edit['end_date'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="source">Type</label>
                <select id="source" name="source">
                    <?php
                    $src = (string) ($edit['source'] ?? 'manual');
                    $opts = ['manual' => 'Handmatig geblokkeerd', 'owner' => 'Eigenaar', 'maintenance' => 'Onderhoud'];
                    foreach ($opts as $value => $label):
                    ?>
                        <option value="<?= h($value) ?>"<?= $src === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="notes">Notitie (optioneel)</label>
            <input type="text" id="notes" name="notes" value="<?= h((string) ($edit['notes'] ?? '')) ?>">
        </div>
        <div class="admin-actions">
            <button class="btn btn-primary" type="submit"><?= $edit ? 'Bewaar wijziging' : 'Blokkeer data' ?></button>
            <?php if ($edit): ?>
                <a class="btn btn-outline" href="availability.php">Annuleer</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="admin-card">
    <h2>Boekingsvenster</h2>
    <p class="admin-help">Aankomst- of vertreksluiting per dag zit niet in het huidige model. Minimum-/maximumverblijf staat bij Prijzen.</p>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="save_windows">
        <div class="form-row">
            <div class="form-group">
                <label for="booking_lead_time_days">Minimale lead time (dagen)</label>
                <input type="number" id="booking_lead_time_days" name="booking_lead_time_days" min="0" max="365" value="<?= h((string) ($app->settings()->get('booking_lead_time_days') ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="booking_max_advance_days">Maximaal vooruit boeken (dagen)</label>
                <input type="number" id="booking_max_advance_days" name="booking_max_advance_days" min="0" max="900" value="<?= h((string) ($app->settings()->get('booking_max_advance_days') ?? '')) ?>">
            </div>
        </div>
        <button class="btn btn-secondary" type="submit">Bewaar venster</button>
    </form>
</section>

<section class="admin-card">
    <h2>Huidige blokkades</h2>
    <?php if ($blocks === []): ?>
        <p class="admin-empty">Nog geen handmatige blokkades. Airbnb-data staan bij Kalenderkoppelingen.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Van</th><th>Tot</th><th>Type</th><th>Notitie</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($blocks as $row): ?>
                    <tr>
                        <td><?= h(admin_format_date((string) $row['start_date'])) ?></td>
                        <td><?= h(admin_format_date((string) $row['end_date'])) ?></td>
                        <td><span class="admin-badge is-<?= h((string) $row['source'] === 'owner' ? 'owner' : ((string) $row['source'] === 'maintenance' ? 'maintenance' : 'manual')) ?>"><?= h(admin_block_label((string) $row['source'])) ?></span></td>
                        <td><?= h((string) ($row['notes'] ?: '—')) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="btn btn-outline" href="availability.php?edit=<?= (int) $row['id'] ?>">Wijzig</a>
                                <form method="post" data-confirm="Deze blokkade verwijderen?">
                                    <?= admin_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-danger" type="submit">Verwijder</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
