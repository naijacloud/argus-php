<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Registered after init: its log line must still be flushed.
register_shutdown_function(static function (): void {
    \NaijaCloud\Argus\Argus::logs()->info('from a later shutdown function');
});
\NaijaCloud\Argus\Argus::metrics()->count('script.runs');
echo "ok\n";
