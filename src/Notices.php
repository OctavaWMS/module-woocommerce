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
        if (Options::getApiKey() !== '' || Options::getLabelEndpoint() !== '' || Options::getRefreshToken() !== '') {
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p>';
        echo esc_html(sprintf(
            /* translators: %s: integration title (may be white-label). */
            __('%s: no API key stored yet. One will be requested automatically on the first order action, or you can connect manually on the Integrations tab.', 'octavawms'),
            UiBranding::integrationTitle()
        ));
        echo '</p></div>';
    }
}
