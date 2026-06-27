<?php

declare(strict_types=1);

namespace WPLM\Client\Storage;

/**
 * Pluggable persistent store for cached tokens (signed payload, CRL, fingerprint).
 */
interface TokenStore
{
    public function read(string $key): ?string;

    public function write(string $key, string $value): void;

    public function delete(string $key): void;
}
