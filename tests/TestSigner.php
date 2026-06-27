<?php

declare(strict_types=1);

namespace WPLM\Client\Tests;

/**
 * Issues WPLM-format signed tokens in tests, mirroring the server's Signer.
 */
final class TestSigner
{
    public string $publicKeyBase64;
    private string $secret;

    public function __construct()
    {
        $keypair               = sodium_crypto_sign_keypair();
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($keypair));
        $this->secret          = sodium_crypto_sign_secretkey($keypair);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function sign(array $payload): string
    {
        $body = self::b64url((string) json_encode($payload));
        $sig  = sodium_crypto_sign_detached($body, $this->secret);
        return $body . '.' . self::b64url($sig);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
