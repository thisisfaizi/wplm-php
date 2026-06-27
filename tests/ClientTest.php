<?php

declare(strict_types=1);

namespace WPLM\Client\Tests;

use PHPUnit\Framework\TestCase;
use WPLM\Client\Client;
use WPLM\Client\Device\DeviceInfo;
use WPLM\Client\Device\DeviceInfoProvider;
use WPLM\Client\Exceptions\WplmLimitExceededException;
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
}
