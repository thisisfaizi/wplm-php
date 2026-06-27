<?php

declare(strict_types=1);

namespace WPLM\Client\Fingerprint;

/**
 * Produces a stable per-install fingerprint (raw; the server hashes it).
 */
interface FingerprintProvider
{
    public function get(): string;
}
