<?php

declare(strict_types=1);

namespace WPLM\Client\Http;

/**
 * A raw HTTP response.
 */
final class Response
{
    public function __construct(
        public int $statusCode,
        public string $body
    ) {
    }
}
