<?php

declare(strict_types=1);

namespace WPLM\Client\WordPress;

use WPLM\Client\Device\DeviceInfo;
use WPLM\Client\Device\DeviceInfoProvider;

/**
 * Device-info provider for a WordPress site: reports the site name, the site
 * host, and the WordPress + PHP versions so each licensed install is
 * identifiable in the vendor dashboard.
 */
final class WordPressDeviceInfoProvider implements DeviceInfoProvider
{
    public function get(): DeviceInfo
    {
        global $wp_version;

        $home = home_url();
        $host = null;
        if (is_string($home)) {
            $parsed = wp_parse_url($home, PHP_URL_HOST);
            $host   = is_string($parsed) && $parsed !== '' ? $parsed : null;
        }

        $name    = get_bloginfo('name');
        $name    = is_string($name) && $name !== '' ? $name : $host;
        $version = is_string($wp_version) && $wp_version !== '' ? $wp_version : '?';

        return new DeviceInfo(
            name: $name,
            hostname: $host,
            platform: 'WordPress ' . $version . ' · PHP ' . PHP_VERSION,
            appVersion: null
        );
    }
}
