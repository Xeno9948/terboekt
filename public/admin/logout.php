<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';
$app = terboekt_boot();
$app->auth()->logout();
header('Location: /admin/', true, 302);
exit;
