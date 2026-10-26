<?php

declare(strict_types=1);

if (getenv('WITH_PREVIOUS') === '1') {
    set_exception_handler(static function (\Throwable $e): void {
        echo 'previous handler saw: ' . $e->getMessage() . "\n";
    });
}

require __DIR__ . '/bootstrap.php';

\NaijaCloud\Argus\Argus::logs()->info('about to fail');

function placeOrder(): void
{
    throw new \RuntimeException('uncaught in script');
}

placeOrder();
