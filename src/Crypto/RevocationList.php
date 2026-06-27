<?php

declare(strict_types=1);

namespace WPLM\Client\Crypto;

/**
 * A verified Certificate Revocation List.
 *
 * A client can match its own license key offline (it knows the plaintext key, so
 * it can compute the SHA-256), but cannot recompute the server-side HMAC of its
 * fingerprint — device revocation is enforced online via /validate.
 */
final class RevocationList
{
    /**
     * @param array<int,string> $revokedKeyHashes
     * @param array<int,string> $revokedFingerprints
     */
    public function __construct(
        public array $revokedKeyHashes,
        public array $revokedFingerprints,
        public ?string $generatedAt = null
    ) {
    }

    public static function parse(string $token, SignatureVerifier $verifier): self
    {
        $payload = $verifier->verify($token);

        $asStrings = static function ($value): array {
            return is_array($value)
                ? array_values(array_filter($value, 'is_string'))
                : [];
        };

        $generated = $payload['generated_at'] ?? null;

        return new self(
            $asStrings($payload['revoked_keys'] ?? null),
            $asStrings($payload['revoked_fingerprints'] ?? null),
            is_string($generated) ? $generated : null
        );
    }

    public function isKeyRevoked(string $licenseKey): bool
    {
        return in_array(hash('sha256', $licenseKey), $this->revokedKeyHashes, true);
    }
}
