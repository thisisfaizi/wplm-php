<?php

declare(strict_types=1);

namespace WPLM\Client\Models;

/**
 * A WPLM license, as returned by /validate. Dates are kept as ISO-8601 strings.
 */
final class License
{
    public function __construct(
        public int $id,
        public int $status,
        public string $statusLabel,
        public int $activationCount,
        public bool $isFloating,
        public string $overageStrategy,
        public int $graceDays,
        public int $source,
        public ?int $productId = null,
        public ?int $orderId = null,
        public ?int $userId = null,
        public ?int $maxActivations = null,
        public ?int $validForDays = null,
        public ?string $activatedAt = null,
        public ?string $expiresAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null
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
            $int($data['status'] ?? null) ?? 0,
            is_string($data['status_label'] ?? null) ? $data['status_label'] : '',
            $int($data['activation_count'] ?? null) ?? 0,
            ($data['is_floating'] ?? null) === true || ($data['is_floating'] ?? null) === 1,
            is_string($data['overage_strategy'] ?? null) ? $data['overage_strategy'] : 'deny',
            $int($data['grace_days'] ?? null) ?? 0,
            $int($data['source'] ?? null) ?? 0,
            $int($data['product_id'] ?? null),
            $int($data['order_id'] ?? null),
            $int($data['user_id'] ?? null),
            $int($data['max_activations'] ?? null),
            $int($data['valid_for_days'] ?? null),
            $str($data['activated_at'] ?? null),
            $str($data['expires_at'] ?? null),
            $str($data['created_at'] ?? null),
            $str($data['updated_at'] ?? null)
        );
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }
}
