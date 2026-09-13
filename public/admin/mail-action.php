<?php
declare(strict_types=1);

require __DIR__ . '/_helpers.php';

use Terboekt\Domain\BookingStatus;
use Terboekt\Domain\ValidationException;
use Terboekt\Money;

$app = terboekt_boot();
$ref = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$exp = trim((string) ($_GET['exp'] ?? $_POST['exp'] ?? ''));
$sig = trim((string) ($_GET['sig'] ?? $_POST['sig'] ?? ''));

$error = null;
$done = null;
$booking = null;

try {
    $booking = $app->mailActions()->bookingFromRequest($ref, $action, $exp, $sig);
} catch (ValidationException $e) {
    $error = $e->errors[0] ?? $e->getMessage();
} catch (Throwable $e) {
    $error = 'Deze link werkt niet. Open de boeking in het beheer.';
}

if ($booking !== null && admin_is_post() && $error === null) {
    try {
        $updated = $app->mailActions()->apply($booking, $action);
        $booking = $updated;
        $done = $action === 'approve' ? 'approved' : 'rejected';
    } catch (ValidationException $e) {
        $error = $e->errors[0] ?? $e->getMessage();
    } catch (Throwable $e) {
        $error = 'Er ging iets mis. Probeer opnieuw of open de boeking in het beheer.';
    }
}

$status = $booking !== null ? (string) $booking['status'] : '';
$alreadyApproved = $status === BookingStatus::CONFIRMED && $done === null && $action === 'approve';
$alreadyRejected = $booking !== null && in_array($status, [BookingStatus::REJECTED, BookingStatus::CANCELLED, BookingStatus::EXPIRED], true) && $done === null;

$title = 'Reservatie';
if ($action === 'approve') {
    $title = 'Boeking goedkeuren';
} elseif ($action === 'reject') {
    $title = 'Aanvraag weigeren';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?> — Home Terboekt</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-login">
    <main class="admin-login-wrap">
        <p class="section-kicker">Home Terboekt</p>
        <h1><?= h($title) ?></h1>

        <?php if ($error !== null): ?>
            <p class="admin-flash is-error"><?= h($error) ?></p>
            <p><a class="btn btn-secondary" href="/admin/">Naar beheer</a></p>
        <?php elseif ($done === 'approved' || $alreadyApproved): ?>
            <p class="admin-flash is-success">De boeking is bevestigd. De gast heeft een bevestigingsmail gekregen.</p>
        <?php elseif ($done === 'rejected' || $alreadyRejected): ?>
            <p class="admin-flash is-success">De aanvraag is geweigerd. De data zijn vrijgegeven.</p>
        <?php elseif ($booking !== null): ?>
            <p>Controleer kort en bevestig. Dit opent geen extra scherm in het beheer.</p>
            <dl class="admin-dl">
                <dt>Referentie</dt><dd><?= h((string) $booking['reference']) ?></dd>
                <dt>Gast</dt><dd><?= h((string) $booking['guest_name']) ?><br><?= h((string) $booking['guest_email']) ?></dd>
                <dt>Data</dt><dd><?= h((string) $booking['check_in']) ?> → <?= h((string) $booking['check_out']) ?></dd>
                <dt>Personen</dt><dd><?= (int) $booking['guests'] ?></dd>
                <dt>Totaal</dt><dd><?= h(Money::formatEuro((int) $booking['total_cents'])) ?></dd>
                <dt>Voorschot</dt><dd><?= h(Money::formatEuro((int) $booking['deposit_cents'])) ?></dd>
            </dl>
            <form method="post">
                <input type="hidden" name="ref" value="<?= h($ref) ?>">
                <input type="hidden" name="action" value="<?= h($action) ?>">
                <input type="hidden" name="exp" value="<?= h($exp) ?>">
                <input type="hidden" name="sig" value="<?= h($sig) ?>">
                <?php if ($action === 'approve'): ?>
                    <button class="btn btn-primary" type="submit">Ja, boeking goedkeuren</button>
                <?php else: ?>
                    <button class="btn btn-danger" type="submit">Ja, aanvraag weigeren</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
