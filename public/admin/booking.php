<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';
require __DIR__ . '/_booking_actions.php';

use Terboekt\Domain\BookingStatus;

[$app, $user] = admin_require();

$ref = trim((string) ($_GET['ref'] ?? $_GET['id'] ?? ''));
if ($ref === '') {
    admin_set_flash('error', 'Geen boeking opgegeven.');
    admin_redirect('bookings.php');
}

$booking = $app->bookings()->findByReference($ref);
if ($booking === null && ctype_digit($ref)) {
    $booking = $app->bookings()->findById((int) $ref);
}
if ($booking === null) {
    admin_set_flash('error', 'Boeking niet gevonden.');
    admin_redirect('bookings.php');
}

if (admin_is_post()) {
    admin_handle_booking_post($app, $user, $booking);
}

$history = $app->history()->forBooking((int) $booking['id']);
$emails = $app->emailLogs()->forBooking((int) $booking['id']);
$status = (string) $booking['status'];
$canAsk = BookingStatus::canTransition($status, BookingStatus::AWAITING_DEPOSIT);
$canConfirm = $status === BookingStatus::AWAITING_DEPOSIT;
$canReject = BookingStatus::canTransition($status, BookingStatus::REJECTED);
$canCancel = BookingStatus::canTransition($status, BookingStatus::CANCELLED);
$canHold = in_array($status, [BookingStatus::REQUESTED, BookingStatus::AWAITING_DEPOSIT], true);
$active = BookingStatus::isActive($status);
$maxGuests = $app->settings()->maxGuests();

admin_layout_start('Boeking ' . (string) $booking['reference'], 'bookings', $user);
?>
<p class="admin-help"><a href="bookings.php">← Alle boekingen</a></p>

<section class="admin-card">
    <div class="admin-card-head">
        <h2><?= h((string) $booking['guest_name']) ?></h2>
        <span class="admin-badge <?= h(admin_status_class($status)) ?>"><?= h(admin_status_label($status)) ?></span>
    </div>
    <dl class="admin-dl">
        <dt>Referentie</dt><dd><?= h((string) $booking['reference']) ?></dd>
        <dt>E-mail</dt><dd><a href="mailto:<?= h((string) $booking['guest_email']) ?>"><?= h((string) $booking['guest_email']) ?></a></dd>
        <dt>Telefoon</dt><dd><?= h((string) ($booking['guest_phone'] ?: '—')) ?></dd>
        <dt>Aankomst</dt><dd><?= h(admin_format_date((string) $booking['check_in'])) ?></dd>
        <dt>Vertrek</dt><dd><?= h(admin_format_date((string) $booking['check_out'])) ?></dd>
        <dt>Nachten</dt><dd><?= (int) $booking['nights'] ?></dd>
        <dt>Gasten</dt><dd><?= (int) $booking['guests'] ?></dd>
        <dt>Pakket</dt><dd><?= h($booking['package_code'] ? admin_package_label((string) $booking['package_code']) : '—') ?></dd>
        <dt>Seizoen</dt><dd><?= h(admin_season_label(isset($booking['season']) ? (string) $booking['season'] : null)) ?></dd>
        <dt>Taal</dt><dd><?= h((string) $booking['language']) ?></dd>
        <dt>Bron</dt><dd><?= h((string) $booking['source']) ?></dd>
        <dt>Bericht gast</dt><dd><?= nl2br(h((string) ($booking['guest_message'] ?: '—'))) ?></dd>
        <dt>Verblijf</dt><dd><?= h(admin_money((int) $booking['accommodation_cents'])) ?></dd>
        <dt>Schoonmaak</dt><dd><?= h(admin_money((int) $booking['cleaning_fee_cents'])) ?></dd>
        <dt>Toeristenbelasting</dt><dd><?= h(admin_money((int) $booking['tourist_tax_cents'])) ?></dd>
        <dt>Totaal</dt><dd><strong><?= h(admin_money((int) $booking['total_cents'])) ?></strong></dd>
        <dt>Voorschot</dt><dd><?= h(admin_money((int) $booking['deposit_cents'])) ?></dd>
        <dt>Restbedrag</dt><dd><?= h(admin_money((int) $booking['remaining_cents'])) ?></dd>
        <dt>Waarborg</dt><dd><?= h(admin_money((int) $booking['security_deposit_cents'])) ?> (apart, terugbetaalbaar)</dd>
        <dt>Voorschot vóór</dt><dd><?= h(admin_format_dt(isset($booking['deposit_due_at']) ? (string) $booking['deposit_due_at'] : null)) ?></dd>
        <dt>Voorschot ontvangen</dt><dd><?= h(admin_format_dt(isset($booking['deposit_received_at']) ? (string) $booking['deposit_received_at'] : null)) ?></dd>
        <dt>Aangemaakt</dt><dd><?= h(admin_format_dt((string) $booking['created_at'])) ?></dd>
    </dl>
</section>

<?php if ($canAsk || $canConfirm || $canReject || $canCancel || $canHold): ?>
<section class="admin-card">
    <h2>Acties</h2>
    <p class="admin-help">Een boeking wordt nooit vanzelf bevestigd. Alleen de knop hieronder na het aanvinken telt.</p>
    <div class="admin-actions">
        <?php if ($canAsk): ?>
            <form method="post">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="ask_deposit">
                <button class="btn btn-primary" type="submit">Vraag voorschot</button>
            </form>
        <?php endif; ?>
        <?php if ($canHold): ?>
            <form method="post" data-confirm="Hold vrijgeven en data openzetten?">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="release_hold">
                <button class="btn btn-outline" type="submit">Geef hold vrij</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($canConfirm): ?>
        <form class="admin-form admin-confirm-box" method="post" style="margin-top:1rem;">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="confirm_deposit">
            <p><strong>Bevestig voorschot ontvangen</strong></p>
            <label>
                <input type="checkbox" name="confirm_ack" value="1" required>
                Ik bevestig dat het voorschot van <?= h(admin_money((int) $booking['deposit_cents'])) ?> ontvangen is en dat ik deze boeking nu definitief wil maken.
            </label>
            <p class="admin-help">Zonder dit vakje gebeurt er niets.</p>
            <button class="btn btn-primary" type="submit">Bevestig boeking</button>
        </form>
    <?php elseif ($status === BookingStatus::REQUESTED): ?>
        <p class="admin-help">Eerst voorschot vragen. Daarna kunt u bevestigen wanneer het geld binnen is.</p>
    <?php endif; ?>

    <?php if ($status === BookingStatus::AWAITING_DEPOSIT): ?>
        <form class="admin-form" method="post" style="margin-top:1rem;">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="extend_deadline">
            <div class="form-row">
                <div class="form-group">
                    <label for="days">Deadline verlengen (dagen)</label>
                    <input type="number" id="days" name="days" min="1" max="60" value="7" required>
                </div>
            </div>
            <button class="btn btn-secondary" type="submit">Verleng deadline</button>
        </form>
    <?php endif; ?>

    <div class="admin-grid-2" style="margin-top:1rem;">
        <?php if ($canReject): ?>
            <form class="admin-form" method="post" data-confirm="Deze aanvraag weigeren? De data komen vrij.">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label for="reject_reason">Weigeren</label>
                    <textarea id="reject_reason" name="reason" placeholder="Optionele reden voor de gast"></textarea>
                </div>
                <button class="btn btn-danger" type="submit">Weiger</button>
            </form>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form class="admin-form" method="post" data-confirm="Deze boeking annuleren? De data komen vrij.">
                <?= admin_csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <div class="form-group">
                    <label for="cancel_reason">Annuleren</label>
                    <textarea id="cancel_reason" name="reason" placeholder="Optionele reden"></textarea>
                </div>
                <button class="btn btn-danger" type="submit">Annuleer boeking</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($active): ?>
<section class="admin-card">
    <h2>Wijzigingen</h2>
    <div class="admin-grid-2">
        <form class="admin-form" method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="change_dates">
            <div class="form-row">
                <div class="form-group">
                    <label for="check_in">Aankomst</label>
                    <input type="date" id="check_in" name="check_in" required value="<?= h((string) $booking['check_in']) ?>">
                </div>
                <div class="form-group">
                    <label for="check_out">Vertrek</label>
                    <input type="date" id="check_out" name="check_out" required value="<?= h((string) $booking['check_out']) ?>">
                </div>
            </div>
            <label class="checkbox"><input type="checkbox" name="reprice" value="1" checked> Prijs opnieuw berekenen</label>
            <p class="admin-help">De gast krijgt een wijzigingsmail.</p>
            <button class="btn btn-secondary" type="submit">Wijzig data</button>
        </form>
        <form class="admin-form" method="post">
            <?= admin_csrf_field() ?>
            <input type="hidden" name="action" value="change_guests">
            <div class="form-group">
                <label for="guests">Aantal gasten</label>
                <input type="number" id="guests" name="guests" min="1" max="<?= (int) $maxGuests ?>" value="<?= (int) $booking['guests'] ?>" required>
            </div>
            <label class="checkbox"><input type="checkbox" name="reprice" value="1" checked> Prijs opnieuw berekenen</label>
            <button class="btn btn-secondary" type="submit">Wijzig gasten</button>
        </form>
    </div>
    <form class="admin-form" method="post" style="margin-top:1rem;">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="adjust_price">
        <div class="form-group">
            <label for="total_euros">Totaalprijs aanpassen (€)</label>
            <input type="text" id="total_euros" name="total_euros" inputmode="decimal" value="<?= h(admin_euro_value((int) $booking['total_cents'])) ?>" required>
        </div>
        <p class="admin-help">Voorschot wordt herberekend met het ingestelde percentage. Geen automatische bevestiging.</p>
        <button class="btn btn-secondary" type="submit">Pas prijs aan</button>
    </form>
</section>
<?php endif; ?>

<section class="admin-card">
    <h2>Interne notitie</h2>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="save_notes">
        <div class="form-group">
            <label for="manager_notes">Alleen voor u zichtbaar</label>
            <textarea id="manager_notes" name="manager_notes"><?= h((string) ($booking['manager_notes'] ?? '')) ?></textarea>
        </div>
        <button class="btn btn-secondary" type="submit">Bewaar notitie</button>
    </form>
</section>

<section class="admin-card">
    <h2>E-mail opnieuw sturen</h2>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="resend_email">
        <div class="form-group">
            <label for="template">Bericht</label>
            <select id="template" name="template">
                <option value="booking_request_received">Aanvraag ontvangen</option>
                <option value="bank_transfer_instructions">Overschrijvingsgegevens</option>
                <option value="booking_confirmed">Bevestiging</option>
                <option value="booking_changed">Wijziging</option>
                <option value="booking_rejected">Weigering</option>
                <option value="booking_cancelled">Annulatie</option>
                <option value="booking_expired">Verlopen</option>
            </select>
        </div>
        <button class="btn btn-outline" type="submit">Verstuur opnieuw</button>
    </form>
    <?php if ($emails === []): ?>
        <p class="admin-empty">Nog geen e-mails voor deze boeking.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Wanneer</th><th>Sjabloon</th><th>Naar</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($emails as $row): ?>
                    <tr>
                        <td><?= h(admin_format_dt((string) $row['created_at'])) ?></td>
                        <td><?= h((string) $row['template_key']) ?></td>
                        <td><?= h((string) $row['to_email']) ?></td>
                        <td><?= h((string) $row['status']) ?><?php if (!empty($row['error'])): ?> — <?= h((string) $row['error']) ?><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-card">
    <h2>Geschiedenis</h2>
    <?php if ($history === []): ?>
        <p class="admin-empty">Nog geen statuswijzigingen.</p>
    <?php else: ?>
        <ul class="admin-list">
            <?php foreach ($history as $row): ?>
                <li>
                    <?= h(admin_format_dt((string) $row['created_at'])) ?>
                    · <?= h(admin_status_label((string) ($row['from_status'] ?? ''))) ?>
                    → <?= h(admin_status_label((string) $row['to_status'])) ?>
                    · <?= h((string) $row['actor_type']) ?>
                    <?php if (!empty($row['reason'])): ?> — <?= h((string) $row['reason']) ?><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
