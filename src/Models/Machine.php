<?php

declare(strict_types=1);

namespace WPLM\Client\Models;

/**
 * A device bound to a license, as returned by /activate and /heartbeat.
 */
final class Machine
{
    public function __construct(
        public int $id,
        public int $licenseId,
        public string $fingerprint,
        public int $status,
        public ?string $name = null,
        public ?string $hostname = null,
        public ?string $platform = null,
        public ?string $appVersion = null,
        public ?string $leaseExpiresAt = null,
        public ?string $lastHeartbeatAt = null,
        public ?string $activatedAt = null
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $int = static function ($v): ?int {
            return is_numeric($v) ? (int) $v : null;
        };
        $str = static function ($v): ?string {
            return is_string($v) && $v !== '' ? $v : null;
        };

        return new self(
            $int($data['id'] ?? null) ?? 0,
            $int($data['license_id'] ?? null) ?? 0,
            is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : '',
            $int($data['status'] ?? null) ?? 1,
            $str($data['name'] ?? null),
            $str($data['hostname'] ?? null),
            $str($data['platform'] ?? null),
            $str($data['app_version'] ?? null),
            $str($data['lease_expires_at'] ?? null),
            $str($data['last_heartbeat_at'] ?? null),
            $str($data['activated_at'] ?? null)
        );
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }
}
