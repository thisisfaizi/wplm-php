<?php

declare(strict_types=1);

namespace WPLM\Client\Http;

use WPLM\Client\Exceptions\WplmNetworkException;

/**
 * Default transport built on ext-curl, with timeouts and retries (exponential
 * backoff + jitter) for transient failures.
 */
final class CurlTransport implements Transport
{
    private const RETRYABLE = [429, 500, 502, 503, 504];

    public function __construct(
        private float $timeout = 15.0,
        private int $maxRetries = 2,
        private bool $verifyTls = true
    ) {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $lastError = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep((int) ($this->backoff($attempt) * 1_000_000));
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) ceil($this->timeout),
                CURLOPT_CONNECTTIMEOUT => (int) ceil($this->timeout),
                CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
                CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_POSTFIELDS => $body ?? '',
            ]);

            $result = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errmsg = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0) {
                $lastError = $errmsg;
                continue;
            }

            if (in_array($status, self::RETRYABLE, true) && $attempt < $this->maxRetries) {
                $lastError = 'HTTP ' . $status;
                continue;
            }

            return new Response($status, is_string($result) ? $result : '');
        }

        throw new WplmNetworkException(
            sprintf('Request failed after %d attempt(s): %s', $this->maxRetries + 1, (string) $lastError)
        );
    }

    private function backoff(int $attempt): float
    {
        $base = 0.2 * (1 << ($attempt - 1));
        return $base + (mt_rand(0, 100) / 1000.0);
    }
}
