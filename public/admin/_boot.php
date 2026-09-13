<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

function terboekt_boot(): Terboekt\App
{
    static $app = null;
    if ($app instanceof Terboekt\App) {
        return $app;
    }
    $bootstrap = null;
    foreach ([
        __DIR__ . '/../../backend/bootstrap.php',
        __DIR__ . '/../backend/bootstrap.php',
        __DIR__ . '/../api/app/bootstrap.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            $bootstrap = $candidate;
            break;
        }
    }
    if ($bootstrap === null) {
        http_response_code(500);
        echo 'Backend not found.';
        exit;
    }
    $config = require $bootstrap;
    $app = new Terboekt\App($config, new Terboekt\Database($config));
    return $app;
}
