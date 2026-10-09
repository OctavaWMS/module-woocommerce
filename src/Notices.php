<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

class Notices
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybeShowMissingConfig']);
    }

    public function maybeShowMissingConfig(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        if (! function_exists('get_current_screen') || ! get_current_screen() || get_current_screen()->id !== 'woocommerce_page_wc-settings') {
            return;
        }
        // Read-only screen selection; no state changes occur from these route parameters.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin route selection.
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin route selection.
        $section = isset($_GET['section']) ? sanitize_key((string) wp_unslash($_GET['section'])) : '';
        if ($tab !== 'integration' || $section !== Options::INTEGRATION_ID) {
            return;
        }
        if (Options::getApiKey() !== '' || Options::getLabelEndpoint() !== '' || Options::getRefreshToken() !== '') {
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p>';
        esc_html_e(
            'OctavaWMS Connector: no API key stored yet. One will be requested automatically on the first order action, or you can connect manually on the Integrations tab.',
            'izprati-bulgaria-shipping'
        );
        echo '</p></div>';
    }
}
