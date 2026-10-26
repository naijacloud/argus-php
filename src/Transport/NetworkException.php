<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

/** No HTTP response arrived: DNS, connect, TLS or timeout. Never leaves the SDK. */
final class NetworkException extends \RuntimeException
{
}
