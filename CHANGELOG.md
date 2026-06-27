# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-06-19

### Added
- Initial release: `Client` with validate / activate / deactivate / heartbeat.
- Offline Ed25519 verification of license payloads and the signed CRL.
- Monotonic time-floor: offline expiry enforcement that resists clock rollback.
- `DeviceInfoProvider` (+ `LocalDeviceInfoProvider`) sending real device metadata.
- Pluggable `Transport`, `TokenStore`, `FingerprintProvider`.
- WordPress helper: `LicenseSettings`, `PluginUpdater`, `OptionTokenStore`,
  `WordPressDeviceInfoProvider`.
- Golden-vector crypto tests shared across SDKs.
