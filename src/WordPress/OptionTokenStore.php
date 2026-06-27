<?php

declare(strict_types=1);

namespace WPLM\Client\WordPress;

use WPLM\Client\Storage\TokenStore;

/**
 * A {@see TokenStore} backed by the WordPress options table, so the cached
 * signed payload, CRL, public key, and fingerprint persist across requests.
 */
final class OptionTokenStore implements TokenStore
{
    public function __construct(private string $prefix = 'wplm_store_')
    {
    }

    public function read(string $key): ?string
    {
        $value = get_option($this->prefix . $key, null);
        return is_string($value) ? $value : null;
    }

    public function write(string $key, string $value): void
    {
        // autoload = false: licensing blobs should not load on every request.
        update_option($this->prefix . $key, $value, false);
    }

    public function delete(string $key): void
    {
        delete_option($this->prefix . $key);
    }
}
