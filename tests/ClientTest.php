<?php

declare(strict_types=1);

namespace WPLM\Client\Tests;

use PHPUnit\Framework\TestCase;
use WPLM\Client\Client;
use WPLM\Client\Device\DeviceInfo;
use WPLM\Client\Device\DeviceInfoProvider;
use WPLM\Client\Exceptions\WplmLimitExceededException;
use WPLM\Client\Exceptions\WplmProductMismatchException;
use WPLM\Client\Http\Response;
use WPLM\Client\Storage\InMemoryTokenStore;

final class ClientTest extends TestCase
{
    private function ok(array $data, int $status = 200): Response
    {
        return new Response($status, (string) json_encode(['success' => true, 'data' => $data, 'meta' => []]));
    }

    private function err(string $code, string $message, int $status): Response
    {
        return new Response($status, (string) json_encode([
            'code'    => $code,
            'message' => $message,
            'data'    => ['status' => $status],
        ]));
    }

    public function testActivateSendsDeviceInfo(): void
    {
        $transport = new FakeTransport(fn ($m, $u, $b): Response => $this->ok(
            ['id' => 1, 'license_id' => 1, 'fingerprint' => 'fp', 'status' => 1],
            201
        ));
        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            transport: $transport,
            deviceInfoProvider: new class implements DeviceInfoProvider {
                public function get(): DeviceInfo
                {
                    return new DeviceInfo('srv01', 'srv01.local', 'Linux 6.1 · PHP 8.3', '2.0.0');
                }
            }
        );

        $client->activate();

        $sent = json_decode((string) $transport->calls[0]['body'], true);
        $this->assertSame('srv01', $sent['name']);
        $this->assertSame('srv01.local', $sent['hostname']);
        $this->assertSame('Linux 6.1 · PHP 8.3', $sent['platform']);
        $this->assertSame('2.0.0', $sent['app_version']);
    }

    public function testExplicitArgsOverrideDeviceInfo(): void
    {
        $transport = new FakeTransport(fn ($m, $u, $b): Response => $this->ok(
            ['id' => 1, 'license_id' => 1, 'fingerprint' => 'fp', 'status' => 1],
            201
        ));
        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            transport: $transport,
            deviceInfoProvider: new class implements DeviceInfoProvider {
                public function get(): DeviceInfo
                {
                    return new DeviceInfo('srv01', null, null, null);
                }
            }
        );

        $client->activate(name: 'Custom');

        $sent = json_decode((string) $transport->calls[0]['body'], true);
        $this->assertSame('Custom', $sent['name']);
    }

    public function testMapsServerErrorToTypedException(): void
    {
        $transport = new FakeTransport(fn ($m, $u, $b): Response => $this->err('machine_limit_exceeded', 'No seats', 422));
        $client    = new Client(baseUrl: 'https://example.test', licenseKey: 'KEY', transport: $transport);

        $this->expectException(WplmLimitExceededException::class);
        $client->activate();
    }

    /**
     * @requires extension sodium
     */
    public function testOfflineAcceptsOldPayload(): void
    {
        $signer = new TestSigner();
        $token  = $signer->sign([
            'key'     => 'KEY',
            'expires' => '2099-01-01T00:00:00Z',
            'max'     => 3,
            'iat'     => time() - 30 * 24 * 3600,
        ]);
        $store = new InMemoryTokenStore();
        $store->write('wplm.signed_payload', $token);

        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            publicKeyBase64: $signer->publicKeyBase64,
            transport: new FakeTransport(fn ($m, $u, $b): Response => throw new \WPLM\Client\Exceptions\WplmNetworkException('offline')),
            store: $store,
        );

        $result = $client->validate(offlineOk: true);

        $this->assertTrue($result->valid);
        $this->assertTrue($result->fromCache);
    }

    /**
     * @requires extension sodium
     */
    public function testOfflineExpiredEvenIfClockRolledBack(): void
    {
        $signer  = new TestSigner();
        $now     = time();
        $expires = gmdate('Y-m-d\TH:i:s\Z', $now + 24 * 3600);
        $token   = $signer->sign([
            'key'     => 'KEY',
            'expires' => $expires,
            'max'     => 3,
            'iat'     => $now - 7 * 24 * 3600,
        ]);
        $store = new InMemoryTokenStore();
        $store->write('wplm.signed_payload', $token);
        // A time PAST the expiry was already observed (high-water mark).
        $store->write('wplm.time_floor', (string) ($now + 2 * 24 * 3600));

        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            publicKeyBase64: $signer->publicKeyBase64,
            transport: new FakeTransport(fn ($m, $u, $b): Response => throw new \WPLM\Client\Exceptions\WplmNetworkException('offline')),
            store: $store,
        );

        $result = $client->validate(offlineOk: true);

        $this->assertFalse($result->valid);
        $this->assertSame('expired', $result->code);
    }

    // ----------------------------------------------------------- product binding

    /**
     * @param int|null $pid
     * @return array{0:string,1:string} [token, publicKeyBase64]
     */
    private function signWithPid(?int $pid): array
    {
        $signer = new TestSigner();
        $token  = $signer->sign([
            'key'     => 'KEY',
            'expires' => '2099-01-01T00:00:00Z',
            'max'     => 3,
            'pid'     => $pid,
            'iat'     => time(),
        ]);
        return [$token, $signer->publicKeyBase64];
    }

    private function onlineValidateTransport(string $token): FakeTransport
    {
        return new FakeTransport(function ($m, $u, $b) use ($token): Response {
            if (str_ends_with((string) $u, '/validate')) {
                return $this->ok([
                    'valid'            => true,
                    'license'          => ['id' => 1, 'status' => 1],
                    'signed_payload'   => $token,
                    'needs_activation' => false,
                ]);
            }
            return $this->ok(['crl' => '']);
        });
    }

    /**
     * @requires extension sodium
     */
    public function testOnlineMatchingPidPasses(): void
    {
        [$token, $pubkey] = $this->signWithPid(42);
        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            productId: 42,
            publicKeyBase64: $pubkey,
            transport: $this->onlineValidateTransport($token),
            store: new InMemoryTokenStore(),
        );

        $this->assertTrue($client->validate()->valid);
    }

    /**
     * @requires extension sodium
     */
    public function testOnlineMismatchedPidThrows(): void
    {
        [$token, $pubkey] = $this->signWithPid(99);
        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            productId: 42,
            publicKeyBase64: $pubkey,
            transport: $this->onlineValidateTransport($token),
            store: new InMemoryTokenStore(),
        );

        $this->expectException(WplmProductMismatchException::class);
        $client->validate();
    }

    /**
     * @requires extension sodium
     */
    public function testOfflineMatchingPidPasses(): void
    {
        [$token, $pubkey] = $this->signWithPid(42);
        $store = new InMemoryTokenStore();
        $store->write('wplm.signed_payload', $token);
        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            productId: 42,
            publicKeyBase64: $pubkey,
            transport: new FakeTransport(fn ($m, $u, $b): Response => throw new \WPLM\Client\Exceptions\WplmNetworkException('offline')),
            store: $store,
        );

        $result = $client->validate(offlineOk: true);
        $this->assertTrue($result->valid);
        $this->assertTrue($result->fromCache);
    }

    /**
     * @requires extension sodium
     */
    public function testOfflineMismatchedPidThrows(): void
    {
        [$token, $pubkey] = $this->signWithPid(99);
        $store = new InMemoryTokenStore();
        $store->write('wplm.signed_payload', $token);
        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            productId: 42,
            publicKeyBase64: $pubkey,
            transport: new FakeTransport(fn ($m, $u, $b): Response => throw new \WPLM\Client\Exceptions\WplmNetworkException('offline')),
            store: $store,
        );

        $this->expectException(WplmProductMismatchException::class);
        $client->validate(offlineOk: true);
    }

    /**
     * @requires extension sodium
     */
    public function testOnlineRecoversFromRotatedKey(): void
    {
        // Token signed by the CURRENT key, but the store holds a STALE key.
        [$token, $goodPubkey] = $this->signWithPid(42);
        $stale = new TestSigner();
        $store = new InMemoryTokenStore();
        $store->write('wplm.public_key', $stale->publicKeyBase64);

        $transport = new FakeTransport(function ($m, $u, $b) use ($token, $goodPubkey): Response {
            if (str_ends_with((string) $u, '/validate')) {
                return $this->ok([
                    'valid'            => true,
                    'license'          => ['id' => 1, 'status' => 1],
                    'signed_payload'   => $token,
                    'needs_activation' => false,
                ]);
            }
            if (str_ends_with((string) $u, '/public-key')) {
                return $this->ok(['public_key' => $goodPubkey]);
            }
            return $this->ok(['crl' => '']);
        });

        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            productId: 42,
            // publicKeyBase64 omitted so it reads the stale store key first
            transport: $transport,
            store: $store,
        );

        $this->assertTrue($client->validate()->valid);
    }

    /**
     * @requires extension sodium
     */
    public function testNoProductIdSkipsPidCheck(): void
    {
        [$token, $pubkey] = $this->signWithPid(null); // legacy token, no pid
        $store = new InMemoryTokenStore();
        $store->write('wplm.signed_payload', $token);
        $client = new Client(
            baseUrl: 'https://example.test',
            licenseKey: 'KEY',
            // productId intentionally omitted = opt out
            publicKeyBase64: $pubkey,
            transport: new FakeTransport(fn ($m, $u, $b): Response => throw new \WPLM\Client\Exceptions\WplmNetworkException('offline')),
            store: $store,
        );

        $this->assertTrue($client->validate(offlineOk: true)->valid);
    }
}
