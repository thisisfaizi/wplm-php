<?php

declare(strict_types=1);

namespace WPLM\Client\Storage;

/**
 * Default in-memory store (suitable for a single request lifecycle and tests).
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string,string> */
    private array $data = [];

    public function read(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function write(string $key, string $value): void
    {
        $this->data[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }
}
