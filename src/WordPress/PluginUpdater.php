<?php

declare(strict_types=1);

namespace WPLM\Client\WordPress;

use Closure;
use stdClass;

/**
 * License-gated plugin auto-update for WordPress, backed by the WPLM server's
 * public /updates/check + /updates/download endpoints.
 *
 * Hooks the standard update transient and the "View details" modal. The update
 * check result is cached in a transient (matching the server's download-token
 * TTL) so the dashboard does not hammer the licensing server.
 */
final class PluginUpdater
{
    private string $slug;

    /**
     * @param Closure(): string $licenseKeyProvider Returns the current license key.
     */
    public function __construct(
        private string $baseUrl,
        private int $productId,
        private string $pluginFile,
        private string $version,
        private string $itemName,
        private Closure $licenseKeyProvider,
        private string $channel = 'stable',
        private int $cacheTtl = 3600
    ) {
        $this->slug = dirname($this->pluginFile);
        if ($this->slug === '.' || $this->slug === '') {
            $this->slug = basename($this->pluginFile, '.php');
        }
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'flushCache'], 10, 0);
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public function checkUpdate($transient)
    {
        if (! $transient instanceof stdClass || ! isset($transient->checked)) {
            return $transient;
        }

        $info = $this->remoteCheck();
        if ($info === null || ($info['update_available'] ?? false) !== true) {
            return $transient;
        }

        $update              = new stdClass();
        $update->slug        = $this->slug;
        $update->plugin      = $this->pluginFile;
        $update->new_version = $this->str($info['latest_version'] ?? null, $this->version);
        $update->url         = $this->baseUrl;
        $update->package     = $this->downloadUrl($this->str($info['download_token'] ?? null));
        $update->tested      = '';
        $update->requires    = $this->str($info['min_app_version'] ?? null);

        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $response[$this->pluginFile] = $update;
        $transient->response = $response;
        return $transient;
    }

    /**
     * @param mixed         $result
     * @param string        $action
     * @param object|null   $args
     * @return mixed
     */
    public function pluginInfo($result, $action, $args)
    {
        if (
            $action !== 'plugin_information'
            || ! is_object($args)
            || ! isset($args->slug)
            || $args->slug !== $this->slug
        ) {
            return $result;
        }

        $info = $this->remoteCheck();
        if ($info === null) {
            return $result;
        }

        $response                = new stdClass();
        $response->name          = $this->itemName;
        $response->slug          = $this->slug;
        $response->version       = $this->str($info['latest_version'] ?? null, $this->version);
        $response->requires      = $this->str($info['min_app_version'] ?? null);
        $response->download_link = $this->downloadUrl($this->str($info['download_token'] ?? null));
        $response->sections      = [
            'changelog' => $this->str($info['changelog'] ?? null),
        ];
        return $response;
    }

    public function flushCache(): void
    {
        delete_transient($this->cacheKey());
    }

    /**
     * Query the server for an available update (cached).
     *
     * @return array<string,mixed>|null
     */
    private function remoteCheck(): ?array
    {
        $key = ($this->licenseKeyProvider)();
        if ($key === '') {
            return null;
        }

        $cached = get_transient($this->cacheKey());
        if (is_array($cached)) {
            return $cached;
        }

        $url = add_query_arg(
            [
                'license_key'     => rawurlencode($key),
                'product_id'      => $this->productId,
                'current_version' => rawurlencode($this->version),
                'channel'         => rawurlencode($this->channel),
            ],
            $this->endpoint('/updates/check')
        );

        $response = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return null;
        }

        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ($decoded['success'] ?? null) !== true || ! is_array($decoded['data'] ?? null)) {
            return null;
        }

        /** @var array<string,mixed> $data */
        $data = $decoded['data'];
        set_transient($this->cacheKey(), $data, $this->cacheTtl);
        return $data;
    }

    /**
     * Coerce a mixed value (from a decoded JSON response) to a string.
     *
     * @param mixed $value
     */
    private function str($value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private function downloadUrl(string $token): string
    {
        return add_query_arg(['token' => rawurlencode($token)], $this->endpoint('/updates/download'));
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/wp-json/wplm/v1' . $path;
    }

    private function cacheKey(): string
    {
        return 'wplm_update_' . md5($this->pluginFile . '|' . $this->channel . '|' . $this->version);
    }
}
