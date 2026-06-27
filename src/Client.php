<?php

declare(strict_types=1);

namespace WPLM\Client;

use WPLM\Client\Crypto\RevocationList;
use WPLM\Client\Crypto\SignatureVerifier;
use WPLM\Client\Device\DeviceInfo;
use WPLM\Client\Device\DeviceInfoProvider;
use WPLM\Client\Exceptions\WplmApiException;
use WPLM\Client\Exceptions\WplmConfigException;
use WPLM\Client\Exceptions\WplmException;
use WPLM\Client\Exceptions\WplmNetworkException;
use WPLM\Client\Exceptions\WplmSignatureInvalidException;
use WPLM\Client\Fingerprint\FingerprintProvider;
use WPLM\Client\Fingerprint\PersistedUuidFingerprintProvider;
use WPLM\Client\Http\CurlTransport;
use WPLM\Client\Http\Response;
use WPLM\Client\Http\Transport;
use WPLM\Client\Models\Machine;
use WPLM\Client\Models\ValidationResult;
use WPLM\Client\Storage\InMemoryTokenStore;
use WPLM\Client\Storage\TokenStore;

/**
 * The WPLM client. Talks to a WP License Manager server's wplm/v1 REST API and
 * verifies signed license payloads offline.
 */
final class Client
{
    private const K_SIGNED = 'wplm.signed_payload';
    private const K_PUBKEY = 'wplm.public_key';
    private const K_CRL = 'wplm.crl';
    private const K_CRL_AT = 'wplm.crl_at';
    private const K_TIME_FLOOR = 'wplm.time_floor';

    private string $base;
    private Transport $transport;
    private TokenStore $store;
    private FingerprintProvider $fingerprint;
    private ?DeviceInfoProvider $deviceInfoProvider;
    private ?DeviceInfo $deviceInfoCache = null;
    private ?SignatureVerifier $verifier = null;

    public function __construct(
        string $baseUrl,
        private ?string $licenseKey = null,
        private ?int $productId = null,
        private ?string $publicKeyBase64 = null,
        private int $maxClockDrift = 300,
        private int $crlTtl = 3600,
        ?Transport $transport = null,
        ?TokenStore $store = null,
        ?FingerprintProvider $fingerprintProvider = null,
        ?DeviceInfoProvider $deviceInfoProvider = null
    ) {
        $this->base = self::normalizeBase($baseUrl);
        $this->transport = $transport ?? new CurlTransport();
        $this->store = $store ?? new InMemoryTokenStore();
        $this->fingerprint = $fingerprintProvider ?? new PersistedUuidFingerprintProvider($this->store);
        $this->deviceInfoProvider = $deviceInfoProvider;
    }

    // ------------------------------------------------------------------ ops

    public function validate(bool $offlineOk = false): ValidationResult
    {
        $key = $this->requireKey();
        try {
            $data = $this->post('/validate', [
                'license_key' => $key,
                'fingerprint' => $this->fingerprint->get(),
            ]);
            $result = ValidationResult::fromArray($data);
            if ($result->signedPayload !== null && $result->signedPayload !== '') {
                $this->store->write(self::K_SIGNED, $result->signedPayload);
            }
            // A successful online call is a trusted clock reading — advance the
            // monotonic time floor so a later offline rollback is detectable.
            $this->advanceTimeFloor(time());
            $this->maybeRefreshCrl();
            return $result;
        } catch (WplmNetworkException $e) {
            if ($offlineOk) {
                $offline = $this->validateOffline($key);
                if ($offline !== null) {
                    return $offline;
                }
            }
            throw $e;
        }
    }

    public function activate(
        ?string $name = null,
        ?string $hostname = null,
        ?string $platform = null,
        ?string $appVersion = null
    ): Machine {
        $body = [
            'license_key' => $this->requireKey(),
            'fingerprint' => $this->fingerprint->get(),
        ] + $this->deviceFields($name, $hostname, $platform, $appVersion);
        return Machine::fromArray($this->post('/activate', $body));
    }

    public function deactivate(): bool
    {
        $data = $this->post('/deactivate', [
            'license_key' => $this->requireKey(),
            'fingerprint' => $this->fingerprint->get(),
        ]);
        return ($data['deactivated'] ?? null) === true;
    }

    public function heartbeat(?string $appVersion = null): Machine
    {
        $resolvedVersion = $appVersion ?? $this->deviceInfo()?->appVersion;
        $body = [
            'license_key' => $this->requireKey(),
            'fingerprint' => $this->fingerprint->get(),
        ];
        if ($resolvedVersion !== null && $resolvedVersion !== '') {
            $body['app_version'] = $resolvedVersion;
        }
        return Machine::fromArray($this->post('/heartbeat', $body));
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyOffline(string $token): array
    {
        return $this->getVerifier()->verify($token);
    }

    public function checkCrl(): RevocationList
    {
        $data = $this->get('/crl');
        $token = is_string($data['crl'] ?? null) ? $data['crl'] : '';
        $crl = RevocationList::parse($token, $this->getVerifier());
        $this->store->write(self::K_CRL, $token);
        $this->store->write(self::K_CRL_AT, (string) (int) (microtime(true) * 1000));
        return $crl;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getStore(): TokenStore
    {
        return $this->store;
    }

    // ----------------------------------------------------------- time floor

    private function readTimeFloor(): int
    {
        $raw = $this->store->read(self::K_TIME_FLOOR);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    private function advanceTimeFloor(int $epochSeconds): void
    {
        if ($epochSeconds <= 0) {
            return;
        }
        if ($epochSeconds > $this->readTimeFloor()) {
            $this->store->write(self::K_TIME_FLOOR, (string) $epochSeconds);
        }
    }

    // -------------------------------------------------------- device metadata

    private function deviceInfo(): ?DeviceInfo
    {
        if ($this->deviceInfoProvider === null) {
            return null;
        }
        return $this->deviceInfoCache ??= $this->deviceInfoProvider->get();
    }

    /**
     * Merge explicit args over provider-supplied device info, dropping empties.
     *
     * @return array<string,string>
     */
    private function deviceFields(
        ?string $name,
        ?string $hostname,
        ?string $platform,
        ?string $appVersion
    ): array {
        $info = $this->deviceInfo();
        $candidates = [
            'name' => $name ?? $info?->name,
            'hostname' => $hostname ?? $info?->hostname,
            'platform' => $platform ?? $info?->platform,
            'app_version' => $appVersion ?? $info?->appVersion,
        ];
        $out = [];
        foreach ($candidates as $field => $value) {
            if ($value !== null && $value !== '') {
                $out[$field] = $value;
            }
        }
        return $out;
    }

    // -------------------------------------------------------------- offline

    private function validateOffline(string $key): ?ValidationResult
    {
        $token = $this->store->read(self::K_SIGNED);
        if ($token === null || $token === '') {
            return null;
        }

        $verifier = $this->verifierOrNull(false);
        if ($verifier === null) {
            return null;
        }

        try {
            $payload = $verifier->verify($token);
        } catch (WplmSignatureInvalidException $e) {
            return new ValidationResult(false, 'signature_invalid', null, null, false, true);
        }

        if (!SignatureVerifier::isWithinClockDrift($payload, $this->maxClockDrift)) {
            return new ValidationResult(false, 'clock_drift', null, null, false, true);
        }

        // Monotonic time floor (high-water mark): use the greatest of the device
        // clock, the payload's issue time, and the highest time ever observed, so
        // a rolled-back clock cannot un-expire the license offline.
        $iat = $payload['iat'] ?? null;
        if (is_int($iat) || is_float($iat)) {
            $this->advanceTimeFloor((int) $iat);
        }
        $this->advanceTimeFloor(time());
        $effectiveNow = $this->readTimeFloor();

        $expires = $payload['expires'] ?? null;
        if (is_string($expires) && $expires !== '') {
            $ts = strtotime($expires);
            if ($ts !== false && $effectiveNow > $ts) {
                return new ValidationResult(false, 'expired', null, null, false, true);
            }
        }

        $crl = $this->cachedCrl($verifier);
        if ($crl !== null && $crl->isKeyRevoked($key)) {
            return new ValidationResult(false, 'revoked', null, null, false, true);
        }

        return new ValidationResult(true, null, null, null, false, true);
    }

    private function cachedCrl(SignatureVerifier $verifier): ?RevocationList
    {
        $token = $this->store->read(self::K_CRL);
        if ($token === null || $token === '') {
            return null;
        }
        try {
            return RevocationList::parse($token, $verifier);
        } catch (WplmSignatureInvalidException $e) {
            return null;
        }
    }

    private function maybeRefreshCrl(): void
    {
        $at = $this->store->read(self::K_CRL_AT);
        if ($at !== null && is_numeric($at)) {
            $age = microtime(true) * 1000 - (int) $at;
            if ($age < $this->crlTtl * 1000) {
                return;
            }
        }
        try {
            $this->checkCrl();
        } catch (WplmException $e) {
            // Best-effort; a stale/missing CRL must not break online validation.
        }
    }

    // ------------------------------------------------------------- verifier

    private function getVerifier(): SignatureVerifier
    {
        $verifier = $this->verifierOrNull(true);
        if ($verifier === null) {
            throw new WplmConfigException(
                'No Ed25519 public key available. Pass publicKeyBase64 or call an '
                . 'online method once to fetch and cache it.'
            );
        }
        return $verifier;
    }

    private function verifierOrNull(bool $allowNetwork): ?SignatureVerifier
    {
        if ($this->verifier !== null) {
            return $this->verifier;
        }
        $b64 = $this->publicKeyBase64 ?? $this->store->read(self::K_PUBKEY);
        if (($b64 === null || $b64 === '') && $allowNetwork) {
            $data = $this->get('/public-key');
            $fetched = $data['public_key'] ?? null;
            if (is_string($fetched) && $fetched !== '') {
                $this->store->write(self::K_PUBKEY, $fetched);
                $b64 = $fetched;
            }
        }
        if ($b64 === null || $b64 === '') {
            return null;
        }
        $this->verifier = SignatureVerifier::fromBase64($b64);
        return $this->verifier;
    }

    // ----------------------------------------------------------------- http

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>
     */
    private function post(string $path, array $body): array
    {
        $res = $this->transport->send(
            'POST',
            $this->base . $path,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (string) json_encode($body)
        );
        return $this->unwrap($res);
    }

    /**
     * @return array<string,mixed>
     */
    private function get(string $path): array
    {
        $res = $this->transport->send('GET', $this->base . $path, ['Accept' => 'application/json']);
        return $this->unwrap($res);
    }

    /**
     * @return array<string,mixed>
     */
    private function unwrap(Response $res): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($res->body, true);
        if (!is_array($decoded)) {
            throw new WplmApiException(
                'Unexpected response (HTTP ' . $res->statusCode . ')',
                null,
                $res->statusCode
            );
        }

        if (($decoded['success'] ?? null) === true && is_array($decoded['data'] ?? null)) {
            /** @var array<string,mixed> $data */
            $data = $decoded['data'];
            return $data;
        }

        $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'wplm_unknown_error';
        $message = is_string($decoded['message'] ?? null)
            ? $decoded['message']
            : 'Request failed (HTTP ' . $res->statusCode . ').';
        throw WplmException::fromCode($code, $message, $res->statusCode);
    }

    private function requireKey(): string
    {
        if ($this->licenseKey === null || $this->licenseKey === '') {
            throw new WplmConfigException('No licenseKey configured.');
        }
        return $this->licenseKey;
    }

    private static function normalizeBase(string $baseUrl): string
    {
        return rtrim(trim($baseUrl), '/') . '/wp-json/wplm/v1';
    }
}
