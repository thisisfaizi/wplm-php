<?php

declare(strict_types=1);

namespace WPLM\Client\Http;

/**
 * Pluggable HTTP transport. Inject a custom implementation for proxies,
 * certificate pinning, WordPress (wp_remote_*), or testing.
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     *
     * @throws \WPLM\Client\Exceptions\WplmNetworkException
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): Response;
}
