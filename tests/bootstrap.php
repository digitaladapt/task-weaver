<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Load the test environment. CI copies .env.test -> .env, but loading
// .env.test directly keeps local/test runs deterministic regardless.
if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.test');
}

// The test database lives in var/ — ensure it exists (CI creates it after
// the copy step, but a plain `php vendor/bin/phpunit` should also work).
$testDbDir = dirname(__DIR__).'/var/data';
if (!is_dir($testDbDir)) {
    mkdir($testDbDir, 0777, true);
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
