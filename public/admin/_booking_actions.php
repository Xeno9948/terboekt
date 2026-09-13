<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

use Terboekt\App;
use Terboekt\DateRange;
use Terboekt\Domain\BookingStatus;
use Terboekt\Money;
use Terboekt\Services\BookingRequestService;

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $booking
 */
function admin_handle_booking_post(App $app, array $user, array $booking): void
{
    if (!admin_verify_csrf()) {
        admin_set_flash('error', 'Beveiligingscontrole mislukt. Probeer opnieuw.');
        admin_redirect('booking.php?ref=' . rawurlencode((string) $booking['reference']));
    }

    $action = (string) ($_POST['action'] ?? '');
    $ref = (string) $booking['reference'];
    $id = (int) $booking['id'];
    $actor = (string) $user['email'];
    $mailer = new BookingRequestService($app);

    try {
        match ($action) {
            'ask_deposit' => (static function () use ($app, $mailer, $ref, $actor): void {
                $updated = $app->status()->transition($ref, BookingStatus::AWAITING_DEPOSIT, 'admin', $actor, 'Voorschot gevraagd');
                $mailer->notifyTransition($updated, BookingStatus::AWAITING_DEPOSIT);
                admin_set_flash('success', 'Voorschot gevraagd. De gast krijgt de overschrijvingsmail (als SMTP ingesteld is).');
            })(),
            'approve_now' => (static function () use ($app, $booking, $user): void {
                $updated = $app->mailActions()->apply($booking, 'approve');
                if ((string) $updated['status'] === BookingStatus::CONFIRMED) {
                    admin_set_flash('success', 'Boeking bevestigd. De gast krijgt een bevestigingsmail.');
                }
            })(),
            'confirm_deposit' => (static function () use ($app, $mailer, $ref, $actor, $booking): void {
                if (empty($_POST['confirm_ack'])) {
                    admin_set_flash('error', 'Bevestig eerst het vakje: het voorschot is ontvangen. Een boeking wordt nooit automatisch bevestigd.');
                    return;
                }
                if ((string) $booking['status'] !== BookingStatus::AWAITING_DEPOSIT) {
                    admin_set_flash('error', 'Bevestigen kan pas nadat u om het voorschot hebt gevraagd.');
                    return;
                }
                $updated = $app->status()->transition(
                    $ref,
                    BookingStatus::CONFIRMED,
                    'admin',
                    $actor,
                    'Voorschot ontvangen — bevestigd door beheerder',
                    ['deposit_received_at' => $app->db->now()]
                );
                $mailer->notifyTransition($updated, BookingStatus::CONFIRMED);
                admin_set_flash('success', 'Boeking bevestigd. De gast krijgt een bevestigingsmail.');
            })(),
            'reject' => (static function () use ($app, $mailer, $ref, $actor): void {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $updated = $app->status()->transition(
                    $ref,
                    BookingStatus::REJECTED,
                    'admin',
                    $actor,
                    $reason !== '' ? $reason : 'Geweigerd',
                    ['rejection_reason' => $reason !== '' ? $reason : null]
                );
                $mailer->notifyTransition($updated, BookingStatus::REJECTED, $reason !== '' ? $reason : null);
                admin_set_flash('success', 'Aanvraag geweigerd. De data zijn vrijgegeven.');
            })(),
            'cancel' => (static function () use ($app, $mailer, $ref, $actor): void {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $updated = $app->status()->transition(
                    $ref,
                    BookingStatus::CANCELLED,
                    'admin',
                    $actor,
                    $reason !== '' ? $reason : 'Geannuleerd',
                    ['cancellation_reason' => $reason !== '' ? $reason : null]
                );
                $mailer->notifyTransition($updated, BookingStatus::CANCELLED, $reason !== '' ? $reason : null);
                admin_set_flash('success', 'Boeking geannuleerd. De data zijn vrijgegeven.');
            })(),
            'release_hold' => (static function () use ($app, $mailer, $ref, $actor, $booking): void {
                if (!in_array((string) $booking['status'], [BookingStatus::REQUESTED, BookingStatus::AWAITING_DEPOSIT], true)) {
                    admin_set_flash('error', 'Alleen een openstaande hold kan worden vrijgegeven.');
                    return;
                }
                $updated = $app->status()->transition(
                    $ref,
                    BookingStatus::CANCELLED,
                    'admin',
                    $actor,
                    'Hold vrijgegeven',
                    ['cancellation_reason' => 'Hold vrijgegeven']
                );
                $mailer->notifyTransition($updated, BookingStatus::CANCELLED, 'Hold vrijgegeven');
                admin_set_flash('success', 'Hold vrijgegeven. De data staan weer open.');
            })(),
            'extend_deadline' => (static function () use ($app, $id, $booking): void {
                $days = (int) ($_POST['days'] ?? 0);
                if ($days < 1 || $days > 60) {
                    admin_set_flash('error', 'Kies tussen 1 en 60 extra dagen.');
                    return;
                }
                $base = (string) ($booking['deposit_due_at'] ?: $app->db->now());
                $ts = strtotime($base . ' UTC');
                if ($ts === false) {
                    $ts = time();
                }
                $newDue = gmdate('Y-m-d H:i:s', $ts + $days * 86400);
                $app->bookings()->update($id, ['deposit_due_at' => $newDue]);
                admin_set_flash('success', 'Deadline verlengd tot ' . admin_format_dt($newDue) . ' (UTC).');
            })(),
            'save_notes' => (static function () use ($app, $id): void {
                $notes = trim((string) ($_POST['manager_notes'] ?? ''));
                $app->bookings()->update($id, ['manager_notes' => $notes !== '' ? $notes : null]);
                admin_set_flash('success', 'Interne notitie opgeslagen. De gast ziet dit niet.');
            })(),
            'change_dates' => (static function () use ($app, $booking, $id): void {
                $checkIn = trim((string) ($_POST['check_in'] ?? ''));
                $checkOut = trim((string) ($_POST['check_out'] ?? ''));
                DateRange::of($checkIn, $checkOut);
                $nights = DateRange::of($checkIn, $checkOut)->nights();
                $conflicts = $app->bookings()->findOverlapping($checkIn, $checkOut, BookingStatus::ACTIVE, $id);
                if ($conflicts !== []) {
                    admin_set_flash('error', 'Die data overlappen met een andere boeking.');
                    return;
                }
                foreach ($app->blocks()->findOverlapping($checkIn, $checkOut) as $block) {
                    if ((int) ($block['booking_id'] ?? 0) === $id) {
                        continue;
                    }
                    admin_set_flash('error', 'Die data overlappen met een blokkade of externe kalender.');
                    return;
                }
                $fields = [
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'nights' => $nights,
                ];
                $reprice = !empty($_POST['reprice']);
                if ($reprice) {
                    $quote = $app->pricing()->calculateBookingPrice($checkIn, $checkOut, (int) $booking['guests'], (bool) $booking['sunday_evening_extra']);
                    $fields += [
                        'package_code' => $quote['package'],
                        'season' => $quote['season'],
                        'accommodation_cents' => $quote['accommodation_cents'],
                        'cleaning_fee_cents' => $quote['cleaning_fee_cents'],
                        'tourist_tax_cents' => $quote['tourist_tax_cents'],
                        'sunday_evening_cents' => $quote['sunday_evening_cents'],
                        'extra_fees_cents' => $quote['extra_fees_cents'],
                        'discount_cents' => $quote['discount_cents'],
                        'total_cents' => $quote['total_cents'],
                        'deposit_cents' => $quote['deposit_cents'],
                        'remaining_cents' => $quote['remaining_cents'],
                        'pricing_snapshot' => json_encode($quote, JSON_UNESCAPED_UNICODE),
                    ];
                }
                $updated = $app->bookings()->update($id, $fields);
                $block = $app->db->fetchOne(
                    "SELECT id FROM availability_blocks WHERE booking_id = :id AND source = 'booking'",
                    ['id' => $id]
                );
                if ($block) {
                    $app->db->query(
                        'UPDATE availability_blocks SET start_date = :s, end_date = :e WHERE id = :bid',
                        ['s' => $checkIn, 'e' => $checkOut, 'bid' => (int) $block['id']]
                    );
                }
                $app->email()->sendTemplate('booking_changed', (string) $updated['guest_email'], admin_booking_vars($updated), $id);
                admin_set_flash('success', $reprice ? 'Data en prijs aangepast.' : 'Data aangepast. Prijs ongewijzigd.');
            })(),
            'change_guests' => (static function () use ($app, $booking, $id): void {
                $guests = (int) ($_POST['guests'] ?? 0);
                $max = $app->settings()->maxGuests();
                if ($guests < 1 || $guests > $max) {
                    admin_set_flash('error', "Aantal gasten moet tussen 1 en {$max} liggen.");
                    return;
                }
                $fields = ['guests' => $guests];
                if (!empty($_POST['reprice'])) {
                    $quote = $app->pricing()->calculateBookingPrice(
                        (string) $booking['check_in'],
                        (string) $booking['check_out'],
                        $guests,
                        (bool) $booking['sunday_evening_extra']
                    );
                    $fields += [
                        'tourist_tax_cents' => $quote['tourist_tax_cents'],
                        'extra_fees_cents' => $quote['extra_fees_cents'],
                        'total_cents' => $quote['total_cents'],
                        'deposit_cents' => $quote['deposit_cents'],
                        'remaining_cents' => $quote['remaining_cents'],
                        'pricing_snapshot' => json_encode($quote, JSON_UNESCAPED_UNICODE),
                    ];
                }
                $updated = $app->bookings()->update($id, $fields);
                $app->email()->sendTemplate('booking_changed', (string) $updated['guest_email'], admin_booking_vars($updated), $id);
                admin_set_flash('success', 'Aantal gasten aangepast.');
            })(),
            'adjust_price' => (static function () use ($app, $id, $booking): void {
                $total = admin_parse_euros((string) ($_POST['total_euros'] ?? ''));
                if ($total === null || $total < 0) {
                    admin_set_flash('error', 'Vul een geldig totaalbedrag in.');
                    return;
                }
                $percent = $app->settings()->depositPercentage();
                $deposit = Money::percent($total, $percent);
                $app->bookings()->update($id, [
                    'total_cents' => $total,
                    'deposit_cents' => $deposit,
                    'remaining_cents' => $total - $deposit,
                    'extra_fees_cents' => (int) $booking['extra_fees_cents'],
                ]);
                admin_set_flash('success', 'Prijs aangepast. Voorschot herberekend op ' . $percent . '%.');
            })(),
            'resend_email' => (static function () use ($app, $booking, $id): void {
                $template = (string) ($_POST['template'] ?? '');
                $allowed = [
                    'booking_request_received',
                    'bank_transfer_instructions',
                    'booking_confirmed',
                    'booking_rejected',
                    'booking_expired',
                    'booking_cancelled',
                    'booking_changed',
                ];
                if (!in_array($template, $allowed, true)) {
                    $template = match ((string) $booking['status']) {
                        BookingStatus::AWAITING_DEPOSIT => 'bank_transfer_instructions',
                        BookingStatus::CONFIRMED => 'booking_confirmed',
                        BookingStatus::REJECTED => 'booking_rejected',
                        BookingStatus::EXPIRED => 'booking_expired',
                        BookingStatus::CANCELLED => 'booking_cancelled',
                        default => 'booking_request_received',
                    };
                }
                $result = $app->email()->sendTemplate($template, (string) $booking['guest_email'], admin_booking_vars($booking), $id);
                admin_set_flash(
                    $result['sent'] ? 'success' : 'error',
                    $result['sent'] ? 'E-mail opnieuw verstuurd.' : ('Verzenden mislukt: ' . ($result['error'] ?? 'onbekend'))
                );
            })(),
            default => admin_set_flash('error', 'Onbekende actie.'),
        };
    } catch (\Throwable $e) {
        admin_handle_action_error($e);
    }

    admin_redirect('booking.php?ref=' . rawurlencode($ref));
}
