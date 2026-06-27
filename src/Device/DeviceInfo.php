<?php

declare(strict_types=1);

namespace WPLM\Client\Device;

/**
 * Human-readable metadata about the current device/host, sent to the server on
 * activate/heartbeat so each seat is identifiable. Field names mirror the WPLM
 * `machines` columns; values are host-appropriate (a server, not a handset).
 */
final class DeviceInfo
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $hostname = null,
        public readonly ?string $platform = null,
        public readonly ?string $appVersion = null
    ) {
    }
}
