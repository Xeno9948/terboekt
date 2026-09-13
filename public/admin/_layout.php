<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/_helpers.php';

/** @param array<string, mixed> $user */
function admin_layout_start(string $title, string $active, array $user): void
{
    $flash = admin_take_flash();
    $nav = [
        'index' => ['Overzicht', 'index.php', 'fa-table-columns'],
        'bookings' => ['Boekingen', 'bookings.php', 'fa-calendar-check'],
        'calendar' => ['Kalender', 'calendar.php', 'fa-calendar-days'],
        'rates' => ['Prijzen', 'rates.php', 'fa-tags'],
        'availability' => ['Beschikbaarheid', 'availability.php', 'fa-ban'],
        'guests' => ['Gasten', 'guests.php', 'fa-users'],
        'calendars' => ['Kalenderkoppelingen', 'calendars.php', 'fa-link'],
        'email' => ['E-mail', 'email.php', 'fa-envelope'],
        'settings' => ['Instellingen', 'settings.php', 'fa-gear'],
    ];
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?> — Beheer Home Terboekt</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin">
    <div class="admin-app">
        <aside class="admin-nav" id="admin-nav">
            <a class="admin-brand" href="index.php">
                <span class="admin-brand-kicker">Beheer</span>
                <span class="admin-brand-name">Home Terboekt</span>
            </a>
            <nav>
                <?php foreach ($nav as $key => [$label, $href, $icon]): ?>
                    <a class="admin-nav-link<?= $active === $key ? ' is-active' : '' ?>" href="<?= h($href) ?>">
                        <i class="fa-solid <?= h($icon) ?>" aria-hidden="true"></i>
                        <?= h($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <p class="admin-nav-note">Bevestig nooit zonder het zelf te doen. Gasten kunnen een boeking niet zelf goedkeuren.</p>
        </aside>
        <div class="admin-body">
            <header class="admin-bar">
                <button class="admin-menu-btn" type="button" data-admin-menu aria-controls="admin-nav" aria-expanded="false">
                    <i class="fa-solid fa-bars" aria-hidden="true"></i>
                    <span>Menu</span>
                </button>
                <h1><?= h($title) ?></h1>
                <div class="admin-user">
                    <span><?= h((string) $user['email']) ?></span>
                    <a class="btn btn-outline admin-logout" href="logout.php">Afmelden</a>
                </div>
            </header>
            <main class="admin-main">
                <?php if ($flash): ?>
                    <div class="admin-flash is-<?= h((string) $flash['type']) ?>" role="status"><?= h((string) $flash['message']) ?></div>
                <?php endif; ?>
    <?php
}

function admin_layout_end(): void
{
    ?>
            </main>
        </div>
    </div>
    <div class="admin-nav-backdrop" data-admin-backdrop hidden></div>
    <script src="assets/admin.js"></script>
</body>
</html>
    <?php
}
