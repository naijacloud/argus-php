<?php

declare(strict_types=1);

set_error_handler(static function (int $no, string $msg): bool {
    echo "previous error handler: {$msg}\n";

    return true;
});

require __DIR__ . '/bootstrap.php';

$a = [];
echo $a['missing'] ?? '';
for ($i = 0; $i < 3; $i++) {
    trigger_error('disk nearly full', E_USER_WARNING); // one event: same type, file, line and message
}
trigger_error('disk nearly full', E_USER_WARNING); // another line, another event
@trigger_error('silenced', E_USER_WARNING);
trigger_error('old api', E_USER_DEPRECATED);
\NaijaCloud\Argus\Argus::captureMessage('after the warnings');
echo "still running\n";
