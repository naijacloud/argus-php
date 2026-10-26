<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

/**
 * One POST. Implementations never follow redirects and throw NetworkException
 * (only) when no HTTP response arrived. Swap one in with the `http_client`
 * option, for proxies or tests.
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @param float $timeout seconds for the whole request, connect included
     */
    public function post(string $url, string $body, array $headers, float $timeout): HttpResponse;
}
