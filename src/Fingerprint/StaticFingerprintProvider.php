<?php

declare(strict_types=1);

namespace WPLM\Client\Fingerprint;

/**
 * Always returns a caller-supplied value (e.g. a derived hardware/site id).
 */
final class StaticFingerprintProvider implements FingerprintProvider
{
    public function __construct(private string $value)
    {
    }

    public function get(): string
    {
        return $this->value;
    }
}
