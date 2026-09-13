<?php
declare(strict_types=1);

$bootstrap = null;
foreach ([
    __DIR__ . '/../../backend/bootstrap.php',
    __DIR__ . '/../backend/bootstrap.php',
    __DIR__ . '/app/bootstrap.php',
] as $candidate) {
    if (is_file($candidate)) {
        $bootstrap = $candidate;
        break;
    }
}
if ($bootstrap === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":"backend_missing"}';
    exit;
}

$config = require $bootstrap;
$app = new Terboekt\App($config, new Terboekt\Database($config));
(new Terboekt\Http\ApiKernel($app))->handle();
