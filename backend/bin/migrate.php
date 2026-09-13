<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
$app = new Terboekt\App($config, new Terboekt\Database($config));
(new Terboekt\Migrator($app->db))->migrate();
fwrite(STDOUT, "Migrations complete using " . $app->db->driver() . ".\n");
