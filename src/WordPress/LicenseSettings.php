<?php

declare(strict_types=1);

namespace WPLM\Client\WordPress;

use WPLM\Client\Client;
use WPLM\Client\Exceptions\WplmException;

/**
 * Turnkey WordPress licensing UI: a settings page with a license-key field plus
 * Activate / Deactivate buttons, status persistence, and a daily background
 * re-validation that catches server-side revocation or expiry.
 *
 * Usage (from a plugin):
 *
 *   $license = new LicenseSettings(
 *       baseUrl: 'https://license.vendor.com',
 *       productId: 42,
 *       publicKeyBase64: 'BASE64_ED25519_PUBLIC_KEY',
 *       itemName: 'My Plugin',
 *   );
 *   $license->register();
 *   if ( ! $license->isLicenseActive() ) { // gate premium features }
 */
final class LicenseSettings
{
    private OptionTokenStore $store;

    public function __construct(
        private string $baseUrl,
        private int $productId,
        private string $publicKeyBase64,
        private string $itemName,
        private string $menuSlug = 'wplm-license',
        private string $optionPrefix = 'wplm_',
        private string $capability = 'manage_options',
        private string $parentSlug = 'options-general.php'
    ) {
        $this->store = new OptionTokenStore($this->optionPrefix . 'store_');
    }

    /** Hook the admin page, form handlers, and daily re-validation. */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . $this->action('activate'), [$this, 'handleActivate']);
        add_action('admin_post_' . $this->action('deactivate'), [$this, 'handleDeactivate']);
        add_action($this->optionPrefix . 'revalidate', [$this, 'revalidate']);

        if (! wp_next_scheduled($this->optionPrefix . 'revalidate')) {
            wp_schedule_event(time() + 86400, 'daily', $this->optionPrefix . 'revalidate');
        }
    }

    /** Whether the license is currently considered active. */
    public function isLicenseActive(): bool
    {
        return get_option($this->optionPrefix . 'status') === 'active';
    }

    /** The stored plaintext license key (may be empty). */
    public function licenseKey(): string
    {
        $key = get_option($this->optionPrefix . 'license_key', '');
        return is_string($key) ? $key : '';
    }

    /** Build a client bound to a specific key. */
    private function clientForKey(string $key): Client
    {
        return new Client(
            baseUrl: $this->baseUrl,
            licenseKey: $key,
            productId: $this->productId,
            publicKeyBase64: $this->publicKeyBase64,
            store: $this->store,
            deviceInfoProvider: new WordPressDeviceInfoProvider()
        );
    }

    public function addMenu(): void
    {
        add_submenu_page(
            $this->parentSlug,
            $this->itemName . ' License',
            $this->itemName . ' License',
            $this->capability,
            $this->menuSlug,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can($this->capability)) {
            return;
        }

        $active = $this->isLicenseActive();
        $key    = $this->licenseKey();
        $error  = get_option($this->optionPrefix . 'error', '');
        $action = $active ? $this->action('deactivate') : $this->action('activate');
        $label  = $active ? __('Deactivate License', 'wplm') : __('Activate License', 'wplm');

        echo '<div class="wrap"><h1>' . esc_html($this->itemName . ' License') . '</h1>';

        if (is_string($error) && $error !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
        if ($active) {
            echo '<div class="notice notice-success"><p>' . esc_html__('License active.', 'wplm') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<table class="form-table"><tr><th><label for="wplm_key">'
            . esc_html__('License Key', 'wplm') . '</label></th><td>';
        echo '<input name="license_key" id="wplm_key" type="text" class="regular-text" value="'
            . esc_attr($key) . '"' . ($active ? ' readonly' : '') . '>';
        echo '</td></tr></table>';
        submit_button($label);
        echo '</form></div>';
    }

    public function handleActivate(): void
    {
        $this->authorize('activate');
        $key = isset($_POST['license_key'])
            ? sanitize_text_field(wp_unslash($_POST['license_key']))
            : '';

        try {
            $machine = $this->clientForKey($key)->activate();
            update_option($this->optionPrefix . 'license_key', $key, false);
            update_option($this->optionPrefix . 'status', $machine->isActive() ? 'active' : 'inactive', false);
            update_option($this->optionPrefix . 'error', '', false);
        } catch (WplmException $e) {
            update_option($this->optionPrefix . 'status', 'inactive', false);
            update_option($this->optionPrefix . 'error', $e->getMessage(), false);
        }

        $this->redirectBack();
    }

    public function handleDeactivate(): void
    {
        $this->authorize('deactivate');

        try {
            $this->clientForKey($this->licenseKey())->deactivate();
        } catch (WplmException $e) {
            // Clear locally regardless — the seat is freed on next server sync.
            update_option($this->optionPrefix . 'error', $e->getMessage(), false);
        }

        update_option($this->optionPrefix . 'status', 'inactive', false);
        update_option($this->optionPrefix . 'license_key', '', false);
        $this->redirectBack();
    }

    /** Daily background re-validation: catches revocation / expiry offline-first. */
    public function revalidate(): void
    {
        $key = $this->licenseKey();
        if ($key === '') {
            return;
        }
        try {
            $result = $this->clientForKey($key)->validate(offlineOk: true);
            update_option($this->optionPrefix . 'status', $result->valid ? 'active' : 'inactive', false);
            if (! $result->valid && $result->code !== null) {
                update_option($this->optionPrefix . 'error', 'License ' . $result->code, false);
            }
        } catch (WplmException $e) {
            // Network failure: leave the last known status untouched.
        }
    }

    private function action(string $verb): string
    {
        return $this->optionPrefix . $verb;
    }

    private function authorize(string $verb): void
    {
        if (! current_user_can($this->capability)) {
            wp_die(esc_html__('You are not allowed to do this.', 'wplm'));
        }
        check_admin_referer($this->action($verb));
    }

    private function redirectBack(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . $this->menuSlug));
        exit;
    }
}
