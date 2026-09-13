<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

/** @return array<string, mixed>|null logged-in user, or null after rendering the login page */
function admin_login_or_user(): ?array
{
    $app = terboekt_boot();
    $auth = $app->auth();
    $auth->startSession();
    $error = null;
    $info = null;
    $otpStep = $auth->pendingOtp();

    if (admin_is_post()) {
        $csrf = (string) ($_POST['csrf'] ?? '');
        if (isset($_POST['otp'])) {
            $result = $auth->verifyOtp((string) $_POST['otp'], $csrf);
            if ($result['ok']) {
                header('Location: /admin/', true, 302);
                exit;
            }
            $error = match ($result['error'] ?? '') {
                'rate_limited' => 'Te veel pogingen. Probeer later opnieuw.',
                'otp_locked' => 'Te veel foute codes. Meld u opnieuw aan.',
                'otp_not_pending' => 'De code is niet meer geldig. Meld u opnieuw aan.',
                default => 'Die code klopt niet. Controleer de e-mail en probeer opnieuw.',
            };
            $otpStep = $auth->pendingOtp();
        } elseif (isset($_POST['resend_otp'])) {
            $result = $auth->resendOtp($csrf);
            if ($result['ok']) {
                $info = 'We hebben een nieuwe code gestuurd' . (isset($result['email']) ? ' naar ' . $result['email'] : '') . '.';
            } else {
                $error = match ($result['error'] ?? '') {
                    'rate_limited' => 'Even wachten: u kunt zo nog een code vragen.',
                    'otp_send_failed' => 'De code kon niet verstuurd worden. Controleer de e-mailinstellingen.',
                    default => 'Opnieuw versturen mislukt. Meld u opnieuw aan.',
                };
            }
            $otpStep = $auth->pendingOtp();
        } elseif (isset($_POST['email'], $_POST['password'])) {
            $result = $auth->login(
                (string) $_POST['email'],
                (string) $_POST['password'],
                $csrf
            );
            if (($result['ok'] ?? false) && empty($result['needs_otp'])) {
                header('Location: /admin/', true, 302);
                exit;
            }
            if (!empty($result['needs_otp'])) {
                $otpStep = true;
                $info = 'We stuurden een code naar ' . (string) ($result['email'] ?? 'uw e-mail') . '.';
            } else {
                $error = match ($result['error'] ?? '') {
                    'rate_limited' => 'Te veel pogingen. Probeer later opnieuw.',
                    'otp_send_failed' => 'Aanmelden lukte, maar de code kon niet verstuurd worden. Controleer de e-mailinstellingen.',
                    default => 'Aanmelden mislukt. Controleer e-mail en wachtwoord.',
                };
            }
        }
    }

    $user = $auth->currentUser();
    if ($user) {
        return $user;
    }

    $csrf = Terboekt\Security\Csrf::token();
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Aanmelden — Beheer Home Terboekt</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css?v=c0a063">
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-login">
    <div class="admin-login-wrap">
        <p class="section-kicker">Home Terboekt</p>
        <h1>Beheer</h1>
        <?php if ($otpStep): ?>
            <p class="lead">Vul de code in die we naar uw e-mail stuurden. Die is 10 minuten geldig.</p>
            <div class="form-card">
                <form method="post" action="/admin/" autocomplete="one-time-code">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <div class="form-group">
                        <label for="otp">Aanmeldcode</label>
                        <input type="text" id="otp" name="otp" inputmode="numeric" pattern="[0-9 ]*" maxlength="8" required autofocus autocomplete="one-time-code">
                    </div>
                    <?php if ($info): ?>
                        <p class="admin-flash is-info"><?= h($info) ?></p>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <p class="form-error show"><?= h($error) ?></p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">Bevestig code</button>
                </form>
                <form method="post" action="/admin/" style="margin-top:0.85rem;">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="resend_otp" value="1">
                    <button type="submit" class="btn btn-outline">Stuur nieuwe code</button>
                </form>
            </div>
        <?php else: ?>
            <p class="lead">Alleen voor de eigenaar. Nieuwe aanvragen kunt u ook goedkeuren via de e-mailknop.</p>
            <div class="form-card">
                <form method="post" action="/admin/" autocomplete="on">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <div class="form-group">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" required autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label for="password">Wachtwoord</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                    </div>
                    <?php if ($error): ?>
                        <p class="form-error show"><?= h($error) ?></p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">Aanmelden</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
    <?php
    return null;
}
