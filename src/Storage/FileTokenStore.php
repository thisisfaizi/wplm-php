<?php

declare(strict_types=1);

namespace WPLM\Client\Storage;

/**
 * Persists tokens as a JSON file. A simple cross-platform default for CLIs and
 * servers; for WordPress prefer a transient/option-backed store.
 */
final class FileTokenStore implements TokenStore
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wplm_store.json';
    }

    public function read(string $key): ?string
    {
        $data = $this->load();
        return $data[$key] ?? null;
    }

    public function write(string $key, string $value): void
    {
        $data = $this->load();
        $data[$key] = $value;
        $this->save($data);
    }

    public function delete(string $key): void
    {
        $data = $this->load();
        unset($data[$key]);
        $this->save($data);
    }

    /**
     * @return array<string,string>
     */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * @param array<string,string> $data
     */
    private function save(array $data): void
    {
        file_put_contents($this->path, json_encode($data), LOCK_EX);
    }
}
