# WPLM PHP SDK

Official PHP client for [WP License Manager (WPLM)](https://github.com/wplm), with a
turnkey WordPress licensing + auto-update helper.

- Online **validate / activate / deactivate / heartbeat** against the `wplm/v1` REST API.
- **Offline verification** of Ed25519-signed license payloads and the CRL — works with no network.
- Offline **expiry enforcement** with a monotonic time-floor (resists clock rollback).
- Real **device metadata** (name / host / platform) sent on activation.
- Pluggable **transport**, **storage**, **fingerprint**, and **device-info** providers.
- WordPress helper: drop-in **license settings page** + license-gated **auto-update**.

## Install

```bash
composer require wplm/sdk
```

Requires PHP 8.0+ with `ext-sodium`, `ext-json`, `ext-curl`.

## Quick start (any PHP app)

```php
use WPLM\Client\Client;

$wplm = new Client(
    baseUrl: 'https://license.vendor.com',
    licenseKey: $userKey,
    productId: 42,
    publicKeyBase64: 'BASE64_ED25519_PUBLIC_KEY', // bundle for offline verification
);

$wplm->activate();                       // bind this device (sends real device info)
$result = $wplm->validate(offlineOk: true); // online, falling back to a cached signed payload
if (! $result->valid) {
    // $result->code: expired | revoked | suspended | ...
}
```

## WordPress plugin (settings + gated auto-update)

```php
use WPLM\Client\WordPress\LicenseSettings;
use WPLM\Client\WordPress\PluginUpdater;

$license = new LicenseSettings(
    baseUrl: 'https://license.vendor.com',
    productId: 42,
    publicKeyBase64: 'BASE64_ED25519_PUBLIC_KEY',
    itemName: 'My Plugin',
);
$license->register();

(new PluginUpdater(
    baseUrl: 'https://license.vendor.com',
    productId: 42,
    pluginFile: plugin_basename(__FILE__),
    version: '1.2.3',
    itemName: 'My Plugin',
    licenseKeyProvider: static fn (): string => $license->licenseKey(),
))->register();

if (! $license->isLicenseActive()) {
    // gate premium features
}
```

## Security

Only the **public key**, product id, and server URL are ever shipped — never a private
signing key or any server secret. See [SECURITY.md](SECURITY.md).

## Development

```bash
composer install
composer test      # phpunit (crypto tests require ext-sodium)
composer analyse   # phpstan (max)
composer lint      # phpcs (PSR-12)
```

## License

MIT — see [LICENSE](LICENSE).
