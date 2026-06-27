<?php

declare(strict_types=1);

namespace WPLM\Client\Tests;

use PHPUnit\Framework\TestCase;
use WPLM\Client\Crypto\RevocationList;
use WPLM\Client\Crypto\SignatureVerifier;
use WPLM\Client\Exceptions\WplmSignatureInvalidException;

/**
 * Verifies the SDK against a committed, deterministic golden vector so every
 * SDK (PHP, JS, Dart, Python) checks identical bytes to identical results.
 *
 * @requires extension sodium
 */
final class CryptoTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function golden(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/golden.json');
        /** @var array<string,mixed> $data */
        $data = json_decode($json, true);
        return $data;
    }

    public function testVerifiesGoldenToken(): void
    {
        $g        = $this->golden();
        $verifier = SignatureVerifier::fromBase64((string) $g['public_key_base64']);

        $payload = $verifier->verify((string) $g['token']);

        $this->assertSame('NDV-LLMG-EXNY-RPU1-7T2Q', $payload['key']);
        $this->assertSame(3, $payload['max']);
    }

    public function testRejectsTamperedToken(): void
    {
        $g        = $this->golden();
        $verifier = SignatureVerifier::fromBase64((string) $g['public_key_base64']);

        $tampered = 'A' . substr((string) $g['token'], 1);

        $this->expectException(WplmSignatureInvalidException::class);
        $verifier->verify($tampered);
    }

    public function testCrlDetectsRevokedKey(): void
    {
        $g        = $this->golden();
        $verifier = SignatureVerifier::fromBase64((string) $g['public_key_base64']);

        $crl = RevocationList::parse((string) $g['crl_token'], $verifier);

        $this->assertTrue($crl->isKeyRevoked((string) $g['revoked_key']));
        $this->assertFalse($crl->isKeyRevoked('SOME-OTHER-KEY'));
    }

    public function testClockDriftIsOneSided(): void
    {
        $now = time();
        // Fresh and old payloads are accepted; a future-issued one is rejected.
        $this->assertTrue(SignatureVerifier::isWithinClockDrift(['iat' => $now], 300));
        $this->assertTrue(SignatureVerifier::isWithinClockDrift(['iat' => $now - 3600], 300));
        $this->assertFalse(SignatureVerifier::isWithinClockDrift(['iat' => $now + 3600], 300));
    }
}
