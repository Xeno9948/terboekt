<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

[$app, $user] = admin_require();

$q = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT guest_email,
               MAX(guest_name) AS guest_name,
               MAX(guest_phone) AS guest_phone,
               COUNT(*) AS stays,
               SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) AS confirmed_count,
               SUM(CASE WHEN status = 'CONFIRMED' THEN total_cents ELSE 0 END) AS confirmed_cents,
               MAX(check_in) AS last_check_in
        FROM bookings";
$params = [];
if ($q !== '') {
    $sql .= ' WHERE guest_name LIKE :q OR guest_email LIKE :q2 OR guest_phone LIKE :q3';
    $like = '%' . $q . '%';
    $params = ['q' => $like, 'q2' => $like, 'q3' => $like];
}
$sql .= ' GROUP BY guest_email ORDER BY last_check_in DESC';
$guests = $app->db->fetchAll($sql, $params);

$selected = trim((string) ($_GET['email'] ?? ''));
$history = [];
if ($selected !== '') {
    $history = $app->db->fetchAll(
        'SELECT * FROM bookings WHERE guest_email = :e ORDER BY check_in DESC',
        ['e' => $selected]
    );
}

admin_layout_start('Gasten', 'guests', $user);
?>
<form class="admin-toolbar admin-card" method="get" action="guests.php">
    <div class="form-group">
        <label for="q">Zoeken</label>
        <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Naam, e-mail of telefoon">
    </div>
    <div class="form-group" style="flex:0;align-self:end;">
        <button class="btn btn-primary" type="submit">Zoek</button>
    </div>
</form>

<section class="admin-card">
    <?php if ($guests === []): ?>
        <p class="admin-empty">Nog geen gasten.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Gast</th>
                        <th>Contact</th>
                        <th>Verblijven</th>
                        <th>Bevestigd</th>
                        <th>Laatste aankomst</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($guests as $row): ?>
                    <tr>
                        <td>
                            <a href="guests.php?email=<?= h(rawurlencode((string) $row['guest_email'])) ?>"><?= h((string) $row['guest_name']) ?></a>
                        </td>
                        <td>
                            <?= h((string) $row['guest_email']) ?><br>
                            <span class="caption"><?= h((string) ($row['guest_phone'] ?: '—')) ?></span>
                        </td>
                        <td><?= (int) $row['stays'] ?></td>
                        <td><?= (int) $row['confirmed_count'] ?> · <?= h(admin_money((int) ($row['confirmed_cents'] ?? 0))) ?></td>
                        <td><?= h(admin_format_date(isset($row['last_check_in']) ? (string) $row['last_check_in'] : null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($history !== []): ?>
<section class="admin-card">
    <h2>Verblijven van <?= h((string) $history[0]['guest_name']) ?></h2>
    <ul class="admin-list">
        <?php foreach ($history as $row): ?>
            <li>
                <a href="booking.php?ref=<?= h((string) $row['reference']) ?>"><?= h((string) $row['reference']) ?></a>
                · <?= h(admin_format_date((string) $row['check_in'])) ?> – <?= h(admin_format_date((string) $row['check_out'])) ?>
                · <span class="admin-badge <?= h(admin_status_class((string) $row['status'])) ?>"><?= h(admin_status_label((string) $row['status'])) ?></span>
                · <?= h(admin_money((int) $row['total_cents'])) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php
admin_layout_end();
