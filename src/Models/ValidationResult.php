<?php

declare(strict_types=1);

namespace WPLM\Client\Models;

/**
 * The outcome of WplmClient::validate().
 */
final class ValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $code = null,
        public ?License $license = null,
        public ?string $signedPayload = null,
        public bool $needsActivation = false,
        public bool $fromCache = false
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, bool $fromCache = false): self
    {
        $license = $data['license'] ?? null;

        return new self(
            ($data['valid'] ?? null) === true,
            is_string($data['code'] ?? null) ? $data['code'] : null,
            is_array($license) ? License::fromArray($license) : null,
            is_string($data['signed_payload'] ?? null) ? $data['signed_payload'] : null,
            ($data['needs_activation'] ?? null) === true,
            $fromCache
        );
    }
}
