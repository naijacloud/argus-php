<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

ini_set('memory_limit', '24M');
$chunks = [];
while (true) {
    $chunks[] = str_repeat('x', 1024 * 1024);
}
