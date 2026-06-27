<?php

declare(strict_types=1);

namespace WPLM\Client\Device;

/**
 * Supplies {@see DeviceInfo} for the current device. Implement this to provide
 * host-appropriate metadata (e.g. a WordPress site's name + WP/PHP versions).
 */
interface DeviceInfoProvider
{
    public function get(): DeviceInfo;
}
