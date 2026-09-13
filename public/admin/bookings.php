<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

use Terboekt\Domain\BookingStatus;

[$app, $user] = admin_require();

$q = trim((string) ($_GET['q'] ?? ''));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
if ($status !== '' && !BookingStatus::isKnown($status)) {
    $status = '';
}

$sql = 'SELECT * FROM bookings WHERE 1=1';
$params = [];
if ($status !== '') {
    $sql .= ' AND status = :status';
    $params['status'] = $status;
}
if ($q !== '') {
    $sql .= ' AND (guest_name LIKE :q OR guest_email LIKE :q2 OR guest_phone LIKE :q3 OR reference LIKE :q4)';
    $like = '%' . $q . '%';
    $params['q'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
    $params['q4'] = $like;
}
$sql .= ' ORDER BY created_at DESC LIMIT 250';
$bookings = $app->db->fetchAll($sql, $params);

admin_layout_start('Boekingen', 'bookings', $user);
?>
<form class="admin-toolbar admin-card" method="get" action="bookings.php">
    <div class="form-group">
        <label for="q">Zoeken</label>
        <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Naam, e-mail, telefoon of referentie">
    </div>
    <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Alle statussen</option>
            <?php foreach (BookingStatus::ALL as $st): ?>
                <option value="<?= h($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= h(admin_status_label($st)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="flex:0;align-self:end;">
        <button class="btn btn-primary" type="submit">Toon</button>
    </div>
</form>

<section class="admin-card">
    <?php if ($bookings === []): ?>
        <p class="admin-empty">Geen boekingen gevonden.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table admin-table-cards">
                <thead>
                    <tr>
                        <th>Referentie</th>
                        <th>Gast</th>
                        <th>Data</th>
                        <th>Gasten</th>
                        <th>Totaal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $row): ?>
                    <tr>
                        <td data-label="Referentie"><a href="booking.php?ref=<?= h((string) $row['reference']) ?>"><?= h((string) $row['reference']) ?></a></td>
                        <td data-label="Gast">
                            <?= h((string) $row['guest_name']) ?><br>
                            <span class="caption"><?= h((string) $row['guest_email']) ?></span>
                        </td>
                        <td data-label="Data"><?= h(admin_format_date((string) $row['check_in'])) ?> – <?= h(admin_format_date((string) $row['check_out'])) ?></td>
                        <td data-label="Gasten"><?= (int) $row['guests'] ?></td>
                        <td data-label="Totaal"><?= h(admin_money((int) $row['total_cents'])) ?></td>
                        <td data-label="Status"><span class="admin-badge <?= h(admin_status_class((string) $row['status'])) ?>"><?= h(admin_status_label((string) $row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
