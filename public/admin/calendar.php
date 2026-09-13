<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

use Terboekt\Domain\BookingStatus;

[$app, $user] = admin_require();

$monthParam = (string) ($_GET['month'] ?? substr(admin_today($app), 0, 7));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = substr(admin_today($app), 0, 7);
}
$monthStart = $monthParam . '-01';
$monthDate = new DateTimeImmutable($monthStart);
$nextMonth = $monthDate->modify('first day of next month');
$prevMonth = $monthDate->modify('first day of last month');
$startPad = (int) $monthDate->format('N') - 1;
$gridStart = $startPad > 0 ? $monthDate->sub(new DateInterval('P' . $startPad . 'D')) : $monthDate;
$lastDay = $monthDate->modify('last day of this month');
$endPad = 7 - (int) $lastDay->format('N');
$gridEnd = $endPad > 0 ? $lastDay->add(new DateInterval('P' . $endPad . 'D')) : $lastDay;
$rangeEnd = $gridEnd->modify('+1 day')->format('Y-m-d');
$rangeStart = $gridStart->format('Y-m-d');
$today = admin_today($app);

$bookings = $app->db->fetchAll(
    "SELECT * FROM bookings
     WHERE status IN ('REQUESTED','AWAITING_DEPOSIT','CONFIRMED')
       AND check_in < :end AND check_out > :start
     ORDER BY check_in",
    ['start' => $rangeStart, 'end' => $rangeEnd]
);
$blocks = $app->db->fetchAll(
    "SELECT b.*, c.provider, c.name AS connection_name
     FROM availability_blocks b
     LEFT JOIN calendar_connections c ON c.id = b.calendar_connection_id
     WHERE b.start_date < :end AND b.end_date > :start
     ORDER BY b.start_date",
    ['start' => $rangeStart, 'end' => $rangeEnd]
);

/** @param list<array<string, mixed>> $items */
$itemsOnDay = static function (string $day, array $items, string $startKey, string $endKey): array {
    $out = [];
    foreach ($items as $item) {
        if ((string) $item[$startKey] <= $day && (string) $item[$endKey] > $day) {
            $out[] = $item;
        }
    }
    return $out;
};

$chipClass = static function (array $item): string {
    if (isset($item['status'])) {
        return $item['status'] === BookingStatus::CONFIRMED ? 'is-confirmed' : 'is-pending';
    }
    $source = (string) ($item['source'] ?? '');
    $provider = (string) ($item['provider'] ?? '');
    if ($source === 'owner') {
        return 'is-owner';
    }
    if ($source === 'maintenance') {
        return 'is-maintenance';
    }
    if ($source === 'manual') {
        return 'is-manual';
    }
    if ($provider === 'airbnb' || $source === 'airbnb') {
        return 'is-airbnb';
    }
    if ($provider === 'booking_com') {
        return 'is-booking';
    }
    if ($source === 'ical') {
        return $provider === 'booking_com' ? 'is-booking' : 'is-airbnb';
    }
    if ($source === 'booking') {
        return 'is-pending';
    }
    return 'is-manual';
};

$chipLabel = static function (array $item): string {
    if (isset($item['guest_name'])) {
        $prefix = ($item['status'] ?? '') === BookingStatus::CONFIRMED ? '' : 'Aanvraag: ';
        return $prefix . (string) $item['guest_name'];
    }
    return admin_block_label((string) $item['source'], isset($item['provider']) ? (string) $item['provider'] : null);
};

$dows = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
$cursor = $gridStart;
$days = [];
while ($cursor <= $gridEnd) {
    $days[] = $cursor;
    $cursor = $cursor->modify('+1 day');
}

admin_layout_start('Kalender', 'calendar', $user);
?>
<section class="admin-card">
    <div class="cal-nav">
        <a class="btn btn-outline" href="calendar.php?month=<?= h($prevMonth->format('Y-m')) ?>">Vorige</a>
        <h2><?php
            $monthsNl = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
            echo h($monthsNl[(int) $monthDate->format('n')] . ' ' . $monthDate->format('Y'));
        ?></h2>
        <a class="btn btn-outline" href="calendar.php?month=<?= h($nextMonth->format('Y-m')) ?>">Volgende</a>
    </div>
    <div class="cal-legend">
        <span><i class="cal-dot is-confirmed"></i>Direct bevestigd</span>
        <span><i class="cal-dot is-pending"></i>Aanvraag / voorschot</span>
        <span><i class="cal-dot is-airbnb"></i>Airbnb / extern</span>
        <span><i class="cal-dot is-booking"></i>Booking.com</span>
        <span><i class="cal-dot is-manual"></i>Handmatig</span>
        <span><i class="cal-dot is-owner"></i>Eigenaar</span>
        <span><i class="cal-dot is-maintenance"></i>Onderhoud</span>
    </div>
    <div class="cal-grid">
        <?php foreach ($dows as $dow): ?>
            <div class="cal-dow"><?= h($dow) ?></div>
        <?php endforeach; ?>
        <?php foreach ($days as $day):
            $ymd = $day->format('Y-m-d');
            $inMonth = $day->format('Y-m') === $monthParam;
            $dayBookings = $itemsOnDay($ymd, $bookings, 'check_in', 'check_out');
            $dayBlocks = array_filter(
                $itemsOnDay($ymd, $blocks, 'start_date', 'end_date'),
                static fn(array $b): bool => ($b['source'] ?? '') !== 'booking'
            );
            ?>
            <div class="cal-cell<?= $inMonth ? '' : ' is-out' ?><?= $ymd === $today ? ' is-today' : '' ?>">
                <span class="cal-daynum"><?= h($day->format('j')) ?></span>
                <?php foreach ($dayBookings as $row): ?>
                    <a class="cal-chip <?= h($chipClass($row)) ?>" href="booking.php?ref=<?= h((string) $row['reference']) ?>">
                        <?= h($chipLabel($row)) ?>
                    </a>
                <?php endforeach; ?>
                <?php foreach ($dayBlocks as $row): ?>
                    <span class="cal-chip <?= h($chipClass($row)) ?>"><?= h($chipLabel($row)) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="admin-help" style="margin-top:0.85rem;">Vertrekdag telt vrij: een boeking tot zondag bezet de nachten tot zaterdag. Booking.com verschijnt pas na een koppeling.</p>
</section>
<?php
admin_layout_end();
