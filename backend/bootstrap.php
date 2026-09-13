<?php
declare(strict_types=1);

namespace Terboekt;

use Terboekt\Security\AppKey;

/**
 * Backend root = this directory. Public site = sibling `public/` in the repo,
 * or the parent folder when `backend/` is uploaded next to the document root.
 */
define('TERBOEKT_BACKEND', __DIR__);

$repoCandidate = dirname(__DIR__);
$publicRoot = is_dir($repoCandidate . '/public')
    ? $repoCandidate . '/public'
    : $repoCandidate;
define('TERBOEKT_PUBLIC', $publicRoot);
define('TERBOEKT_REPO', is_dir($repoCandidate . '/public') ? $repoCandidate : dirname($publicRoot));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Terboekt\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = TERBOEKT_BACKEND . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once TERBOEKT_BACKEND . '/src/Domain/Exceptions.php';

Env::load(TERBOEKT_REPO . '/.env');
Env::load(TERBOEKT_BACKEND . '/.env');
Env::load(TERBOEKT_PUBLIC . '/../.env');

$config = Config::fromEnv();
date_default_timezone_set($config->timezone);

AppKey::ensure($config);

return $config;
