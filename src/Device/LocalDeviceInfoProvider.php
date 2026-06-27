<?php

declare(strict_types=1);

namespace WPLM\Client\Device;

/**
 * Default provider: derives info from the host machine — hostname plus the OS
 * and PHP version.
 */
final class LocalDeviceInfoProvider implements DeviceInfoProvider
{
    public function __construct(private ?string $appVersion = null)
    {
    }

    public function get(): DeviceInfo
    {
        $host = gethostname();
        $host = $host === false ? null : $host;

        return new DeviceInfo(
            name: $host,
            hostname: $host,
            platform: php_uname('s') . ' ' . php_uname('r') . ' · PHP ' . PHP_VERSION,
            appVersion: $this->appVersion
        );
    }
}
