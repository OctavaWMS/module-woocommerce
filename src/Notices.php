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
        if (Options::getLabelEndpoint() !== '') {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        esc_html_e(
            'OctavaWMS: connect your store or set the label endpoint under WooCommerce → Settings → Integrations → OctavaWMS.',
            'octavawms'
        );
        echo '</p></div>';
    }
}
