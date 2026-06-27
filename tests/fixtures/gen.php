<?php

declare(strict_types=1);

// Generates a deterministic golden crypto vector shared across SDKs (PHP, JS).
// Run with a sodium-enabled PHP:  php tests/fixtures/gen.php > golden.json

function b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function sign_token(array $payload, string $secret): string
{
    $body = b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig  = sodium_crypto_sign_detached($body, $secret);
    return $body . '.' . b64url($sig);
}

$seed   = str_repeat("\x01", 32);
$kp     = sodium_crypto_sign_seed_keypair($seed);
$pub    = sodium_crypto_sign_publickey($kp);
$secret = sodium_crypto_sign_secretkey($kp);

$payload = [
    'key'     => 'NDV-LLMG-EXNY-RPU1-7T2Q',
    'expires' => '2099-01-01T00:00:00Z',
    'max'     => 3,
    'iat'     => 1700000000,
];
$token = sign_token($payload, $secret);

$revokedKey = 'REVOKED-KEY-1';
$crlPayload = [
    'revoked_keys'         => [hash('sha256', $revokedKey)],
    'revoked_fingerprints' => [],
    'generated_at'         => '2026-01-01T00:00:00Z',
    'iat'                  => 1700000000,
];
$crl = sign_token($crlPayload, $secret);

echo json_encode(
    [
        'public_key_base64' => base64_encode($pub),
        'token'             => $token,
        'payload'           => $payload,
        'crl_token'         => $crl,
        'revoked_key'       => $revokedKey,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
