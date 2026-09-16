<?php

declare(strict_types=1);

// Local-only runner: the worker composer.json shares the root project's
// symfony deps, so the root vendor tree is sufficient. Registers the worker's
// PSR-4 mapping and the test namespace.

$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
$loader->addPsr4('TaskWeaverWorker\\', dirname(__DIR__).'/src');
$loader->addPsr4('TaskWeaverWorker\\Tests\\', __DIR__);