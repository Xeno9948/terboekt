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

    if (admin_is_post() && isset($_POST['email'], $_POST['password'])) {
        $result = $auth->login(
            (string) $_POST['email'],
            (string) $_POST['password'],
            (string) ($_POST['csrf'] ?? '')
        );
        if ($result['ok']) {
            header('Location: /admin/', true, 302);
            exit;
        }
        $error = $result['error'] === 'rate_limited'
            ? 'Te veel pogingen. Probeer later opnieuw.'
            : 'Aanmelden mislukt. Controleer e-mail en wachtwoord.';
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
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-login">
    <div class="admin-login-wrap">
        <p class="section-kicker">Home Terboekt</p>
        <h1>Beheer</h1>
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
    </div>
</body>
</html>
    <?php
    return null;
}
