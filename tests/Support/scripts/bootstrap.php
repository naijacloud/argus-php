<?php

declare(strict_types=1);

// Shared by the child-process scripts: plain PHP, the way an app would start.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

\NaijaCloud\Argus\init([
    'release' => 'script@1',
    'environment' => 'test',
    'flush_timeout_ms' => 3000,
]);
