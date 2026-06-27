<?php

declare(strict_types=1);

namespace WPLM\Client\Fingerprint;

use WPLM\Client\Storage\TokenStore;

/**
 * Generates a random fingerprint once and persists it in the store, so it stays
 * stable for the lifetime of the install.
 */
final class PersistedUuidFingerprintProvider implements FingerprintProvider
{
    public function __construct(
        private TokenStore $store,
        private string $storageKey = 'wplm.fingerprint'
    ) {
    }

    public function get(): string
    {
        $existing = $this->store->read($this->storageKey);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $fingerprint = bin2hex(random_bytes(32));
        $this->store->write($this->storageKey, $fingerprint);
        return $fingerprint;
    }
}
