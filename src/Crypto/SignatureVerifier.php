<?php

declare(strict_types=1);

namespace WPLM\Client\Crypto;

use WPLM\Client\Exceptions\WplmSignatureInvalidException;

/**
 * Verifies WPLM signed tokens (license payloads and the CRL) offline using the
 * server's Ed25519 public key.
 *
 * Token format: base64url(json) . "." . base64url(detached_signature), where the
 * signature is over the base64url(json) string. The public key is the standard
 * base64 value from /public-key.
 */
final class SignatureVerifier
{
    private string $publicKey;

    public function __construct(string $publicKeyBytes)
    {
        $this->publicKey = $publicKeyBytes;
    }

    public static function fromBase64(string $publicKeyBase64): self
    {
        $raw = base64_decode(trim($publicKeyBase64), true);
        if ($raw === false) {
            throw new WplmSignatureInvalidException('Invalid public key encoding');
        }
        return new self($raw);
    }

    /**
     * @return array<string,mixed>
     */
    public function verify(string $token): array
    {
        $dot = strpos($token, '.');
        if ($dot === false || $dot === 0 || $dot >= strlen($token) - 1) {
            throw new WplmSignatureInvalidException('Malformed signed token');
        }

        $body = substr($token, 0, $dot);
        $signature = self::base64UrlDecode(substr($token, $dot + 1));

        if ($signature === '' || $this->publicKey === '') {
            throw new WplmSignatureInvalidException('Signature verification failed');
        }

        if (!sodium_crypto_sign_verify_detached($signature, $body, $this->publicKey)) {
            throw new WplmSignatureInvalidException('Signature verification failed');
        }

        /** @var mixed $payload */
        $payload = json_decode(self::base64UrlDecode($body), true);
        if (!is_array($payload)) {
            throw new WplmSignatureInvalidException('Signed payload is not a JSON object');
        }
        /** @var array<string,mixed> $payload */
        return $payload;
    }

    /**
     * Whether a cached payload's `iat` is acceptable (one-sided check).
     *
     * Rejects only a payload that appears issued in the *future* by more than
     * $maxDriftSeconds (the device clock was rolled back). An arbitrarily old
     * payload is fine — offline validity is governed by `expires`, not the drift
     * window — otherwise offline use would stop working after $maxDriftSeconds.
     *
     * @param array<string,mixed> $payload
     */
    public static function isWithinClockDrift(array $payload, int $maxDriftSeconds): bool
    {
        $iat = $payload['iat'] ?? null;
        if (!is_int($iat) && !is_float($iat)) {
            return true;
        }
        return (int) $iat - time() <= $maxDriftSeconds;
    }

    private static function base64UrlDecode(string $input): string
    {
        $normalized = strtr($input, '-_', '+/');
        $remainder = strlen($normalized) % 4;
        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new WplmSignatureInvalidException('Invalid base64url segment');
        }
        return $decoded;
    }
}
