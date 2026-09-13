<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
$app = new Terboekt\App($config, new Terboekt\Database($config));
(new Terboekt\Migrator($app->db))->migrate();

$email = null;
$password = null;
$role = 'owner';
$name = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $email = substr($arg, 8);
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, 11);
    } elseif (str_starts_with($arg, '--role=')) {
        $role = substr($arg, 7);
    } elseif (str_starts_with($arg, '--name=')) {
        $name = substr($arg, 7);
    }
}
$email = $email ?: Terboekt\Env::get('ADMIN_SETUP_EMAIL');
$password = $password ?: Terboekt\Env::get('ADMIN_SETUP_PASSWORD');
if (!$email || !$password) {
    fwrite(STDERR, "Usage: php backend/bin/create-admin.php --email=owner@example.com --password='long-secret'\n");
    fwrite(STDERR, "Or set ADMIN_SETUP_EMAIL and ADMIN_SETUP_PASSWORD in the environment (not in git).\n");
    exit(1);
}

$user = $app->auth()->createAdmin($email, $password, $role, $name);
fwrite(STDOUT, "Admin ready {$user['email']} role {$user['role']} (id {$user['id']}).\n");
