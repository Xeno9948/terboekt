<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
require_once __DIR__ . '/_login_view.php';

use Terboekt\Domain\BookingStatus;

$user = admin_login_or_user();
if ($user === null) {
    return;
}
$app = terboekt_boot();
$today = admin_today($app);
$soon = gmdate('Y-m-d H:i:s', time() + 2 * 86400);
$arrivalUntil = \Terboekt\DateRange::addDays($today, 14);

$count = static function (Terboekt\App $app, string $sql, array $params = []) : int {
    $row = $app->db->fetchOne($sql, $params);
    return (int) ($row['c'] ?? 0);
};

$pending = $count($app, "SELECT COUNT(*) AS c FROM bookings WHERE status = :s", ['s' => BookingStatus::REQUESTED]);
$awaiting = $count($app, "SELECT COUNT(*) AS c FROM bookings WHERE status = :s", ['s' => BookingStatus::AWAITING_DEPOSIT]);
$confirmed = $count($app, "SELECT COUNT(*) AS c FROM bookings WHERE status = :s AND check_out > :today", [
    's' => BookingStatus::CONFIRMED,
    'today' => $today,
]);
$expiring = $app->db->fetchAll(
    "SELECT * FROM bookings
     WHERE (status = :await AND deposit_due_at IS NOT NULL AND deposit_due_at <= :soon_deposit)
        OR (status = :req AND created_at <= :soon_created)
     ORDER BY COALESCE(deposit_due_at, created_at) ASC
     LIMIT 20",
    [
        'await' => BookingStatus::AWAITING_DEPOSIT,
        'req' => BookingStatus::REQUESTED,
        'soon_deposit' => $soon,
        'soon_created' => $soon,
    ]
);
$arrivals = $app->db->fetchAll(
    "SELECT * FROM bookings WHERE status = :s AND check_in >= :today AND check_in < :until ORDER BY check_in",
    ['s' => BookingStatus::CONFIRMED, 'today' => $today, 'until' => $arrivalUntil]
);
$departures = $app->db->fetchAll(
    "SELECT * FROM bookings WHERE status = :s AND check_out >= :today AND check_out < :until ORDER BY check_out",
    ['s' => BookingStatus::CONFIRMED, 'today' => $today, 'until' => $arrivalUntil]
);
$expected = $app->db->fetchOne(
    "SELECT COALESCE(SUM(deposit_cents), 0) AS cents, COUNT(*) AS c FROM bookings WHERE status = :s",
    ['s' => BookingStatus::AWAITING_DEPOSIT]
);
$received = $app->db->fetchOne(
    "SELECT COALESCE(SUM(deposit_cents), 0) AS cents, COUNT(*) AS c
     FROM bookings
     WHERE deposit_received_at IS NOT NULL OR status = :s",
    ['s' => BookingStatus::CONFIRMED]
);
$activity = $app->db->fetchAll(
    "SELECT h.*, b.reference, b.guest_name
     FROM booking_status_history h
     JOIN bookings b ON b.id = h.booking_id
     ORDER BY h.created_at DESC, h.id DESC
     LIMIT 12"
);

admin_layout_start('Overzicht', 'index', $user);
?>
<section class="admin-metrics">
    <a class="admin-metric is-warn" href="bookings.php?status=REQUESTED">
        <span class="kicker">Openstaande aanvragen</span>
        <strong><?= (int) $pending ?></strong>
    </a>
    <a class="admin-metric is-warn" href="bookings.php?status=AWAITING_DEPOSIT">
        <span class="kicker">Wacht op voorschot</span>
        <strong><?= (int) $awaiting ?></strong>
    </a>
    <a class="admin-metric is-alert" href="bookings.php?status=AWAITING_DEPOSIT">
        <span class="kicker">Verloopt binnenkort</span>
        <strong><?= count($expiring) ?></strong>
    </a>
    <div class="admin-metric">
        <span class="kicker">Aankomsten (14 d.)</span>
        <strong><?= count($arrivals) ?></strong>
    </div>
    <div class="admin-metric">
        <span class="kicker">Vertrekken (14 d.)</span>
        <strong><?= count($departures) ?></strong>
    </div>
    <a class="admin-metric" href="bookings.php?status=CONFIRMED">
        <span class="kicker">Bevestigd (toekomst)</span>
        <strong><?= (int) $confirmed ?></strong>
    </a>
    <div class="admin-metric">
        <span class="kicker">Verwachte voorschotten</span>
        <strong><?= h(admin_money((int) ($expected['cents'] ?? 0))) ?></strong>
    </div>
    <div class="admin-metric">
        <span class="kicker">Voorschotten ontvangen</span>
        <strong><?= h(admin_money((int) ($received['cents'] ?? 0))) ?></strong>
    </div>
</section>

<div class="admin-grid-2">
    <section class="admin-card">
        <div class="admin-card-head">
            <h2>Verloopt binnenkort</h2>
            <a href="bookings.php">Alle boekingen</a>
        </div>
        <?php if ($expiring === []): ?>
            <p class="admin-empty">Niets dat binnen 48 uur verloopt.</p>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($expiring as $row): ?>
                    <li>
                        <a href="booking.php?ref=<?= h((string) $row['reference']) ?>"><?= h((string) $row['reference']) ?></a>
                        · <?= h((string) $row['guest_name']) ?>
                        · <?= h(admin_status_label((string) $row['status'])) ?>
                        · <?= h(admin_format_date((string) $row['check_in'])) ?>–<?= h(admin_format_date((string) $row['check_out'])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="admin-card">
        <h2>Aankomst en vertrek</h2>
        <h3>Aankomsten</h3>
        <?php if ($arrivals === []): ?>
            <p class="admin-empty">Geen aankomsten in de komende 14 dagen.</p>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($arrivals as $row): ?>
                    <li>
                        <?= h(admin_format_date((string) $row['check_in'])) ?>
                        · <a href="booking.php?ref=<?= h((string) $row['reference']) ?>"><?= h((string) $row['guest_name']) ?></a>
                        · <?= (int) $row['guests'] ?> gasten
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h3>Vertrekken</h3>
        <?php if ($departures === []): ?>
            <p class="admin-empty">Geen vertrekken in de komende 14 dagen.</p>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($departures as $row): ?>
                    <li>
                        <?= h(admin_format_date((string) $row['check_out'])) ?>
                        · <a href="booking.php?ref=<?= h((string) $row['reference']) ?>"><?= h((string) $row['guest_name']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="admin-card">
    <h2>Recente activiteit</h2>
    <?php if ($activity === []): ?>
        <p class="admin-empty">Nog geen activiteit.</p>
    <?php else: ?>
        <ul class="admin-list">
            <?php foreach ($activity as $row): ?>
                <li>
                    <?= h(admin_format_dt((string) $row['created_at'])) ?>
                    · <a href="booking.php?ref=<?= h((string) $row['reference']) ?>"><?= h((string) $row['reference']) ?></a>
                    · <?= h((string) $row['guest_name']) ?>
                    · <?= h(admin_status_label((string) ($row['from_status'] ?? ''))) ?>
                    → <?= h(admin_status_label((string) $row['to_status'])) ?>
                    <?php if (!empty($row['actor_type'])): ?>
                        <span class="caption">(<?= h((string) $row['actor_type']) ?>)</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
