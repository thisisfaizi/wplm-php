<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=4,8,15&height=160&section=header&text=wplm-php&fontSize=52&fontAlignY=42&animation=fadeIn&fontColor=ffffff" />

# WP License Manager — PHP SDK

[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)

<p>PHP SDK for WP License Manager — activate, validate and verify Ed25519-signed software license keys offline<br>
in any PHP app or WordPress plugin, with license-gated auto-updates. Backed by a self-hosted<br>
<a href="https://github.com/thisisfaizi/wp-license-manager">WP License Manager</a> software licensing server for WordPress / WooCommerce.</p>

</div>

---

Official PHP client for **[WP License Manager (WPLM)](https://github.com/thisisfaizi/wp-license-manager)**, with a
turnkey WordPress licensing + auto-update helper.

- ✅ Online `validate` / `activate` / `deactivate` / `heartbeat`
- 🔏 **Offline** Ed25519 signature verification (`ext-sodium`, no network needed)
- 🛡️ Signed CRL check (reject revoked keys offline)
- 🔌 Pluggable transport, storage, fingerprint, and device-info providers
- 🔄 Offline expiry enforcement with monotonic time-floor (resists clock rollback)
- 🖥️ WordPress helper: drop-in **license settings page** + license-gated **auto-update**

---

## Install

> Not yet published to Packagist; install from GitHub for now.

Add this repository as a Composer VCS source, then require the package:

```bash
composer config repositories.wplm-php vcs https://github.com/thisisfaizi/wplm-php
composer require wplm/sdk:dev-main
```

Requires PHP 8.0+ with `ext-sodium`, `ext-json`, `ext-curl`.

---

## Quick Start (Any PHP App)

```php
use WPLM\Client\Client;

$wplm = new Client(
    baseUrl: 'https://license.vendor.com',
    licenseKey: $userKey,
    productId: 42,
    publicKeyBase64: 'BASE64_ED25519_PUBLIC_KEY', // bundle for offline verification
);

$wplm->activate();                          // bind this device (sends real device info)
$result = $wplm->validate(offlineOk: true); // online, falling back to a cached payload
if (! $result->valid) {
    // $result->code: 'expired' | 'revoked' | 'suspended' | ...
}
```

---

## WordPress Plugin (Settings Page + Gated Auto-Update)

```php
use WPLM\Client\WordPress\LicenseSettings;
use WPLM\Client\WordPress\PluginUpdater;

// Admin → Settings → My Plugin License (enter key, activate, deactivate)
$license = new LicenseSettings(
    baseUrl: 'https://license.vendor.com',
    productId: 42,
    publicKeyBase64: 'BASE64_ED25519_PUBLIC_KEY',
    itemName: 'My Plugin',
);
$license->register();

// Gate plugin updates through WPLM's Releases API
(new PluginUpdater(
    baseUrl: 'https://license.vendor.com',
    productId: 42,
    pluginFile: plugin_basename(__FILE__),
    version: '1.2.3',
    itemName: 'My Plugin',
    licenseKeyProvider: static fn (): string => $license->licenseKey(),
))->register();

if (! $license->isLicenseActive()) {
    // disable premium features
}
```

---

## Product Binding

Set `productId` and the SDK rejects any license whose signed product id (`pid`)
does not match — so a key issued for another product cannot run in your app.
Enforced **online and offline** from the cryptographically signed payload.

```php
use WPLM\Client\Exceptions\WplmProductMismatchException;

$wplm = new Client(
    baseUrl: 'https://license.vendor.com',
    licenseKey: $key,
    productId: 42,             // this app only accepts product-42 keys
    publicKeyBase64: PUBLIC_KEY,
);

try {
    $wplm->validate(offlineOk: true);
} catch (WplmProductMismatchException $e) {
    // key belongs to a different product
}
```

Omit `productId` to opt out (any genuine key is accepted). WooCommerce-issued
keys carry their product id automatically; generator/API/CSV keys may need the
admin **Settings → Tools → Re-sign licenses** backfill first.

## Subscription Renewal

Renewals are handled through **WooCommerce My Account → Subscriptions → Renew**.
No SDK code is needed: after the customer pays, the server extends `expires_at`
and re-signs the offline payload. The next `validate()` call returns the updated
expiry and refreshes the local cache automatically.

---

## Security

Only the **public key**, product id, and server URL are ever shipped — never a
private signing key or any server secret. See [SECURITY.md](SECURITY.md).

---

## Development

```bash
composer install
composer test      # phpunit (crypto tests require ext-sodium)
composer analyse   # phpstan (max)
composer lint      # phpcs (PSR-12)
```

---

## Links

- [WP License Manager (server plugin)](https://github.com/thisisfaizi/wp-license-manager)
- [Dart / Flutter SDK](https://github.com/thisisfaizi/wplm-dart)
- [Python SDK](https://github.com/thisisfaizi/wplm-python)
- [JavaScript / TypeScript SDK](https://github.com/thisisfaizi/wplm-js)

---

<div align="center">

MIT License · Part of the [WP License Manager](https://github.com/thisisfaizi/wp-license-manager) ecosystem

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=4,8,15&height=80&section=footer" />

</div>
