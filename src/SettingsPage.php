<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

class SettingsPage extends \WC_Integration
{
    public function __construct()
    {
        $this->id = Options::INTEGRATION_ID;
        $this->method_title = UiBranding::integrationTitle();
        $this->method_description = sprintf(
            /* translators: %s: service name (e.g. OctavaWMS, Изпрати.БГ). */
            __('Connect your store to %s for shipping label generation and order management.', 'octavawms'),
            UiBranding::serviceName()
        );

        // WC_Settings_API / WC_Integration do not declare __construct(); calling parent::__construct() fatal-errors on PHP.
        $this->init_form_fields();
        $this->init_settings();
    }

    public function init_settings(): void
    {
        parent::init_settings();

        if (! is_array($this->settings)) {
            return;
        }

        if (empty($this->settings['api_key'])) {
            $legacy = (string) get_option(Options::LEGACY_API_KEY, '');
            if ($legacy !== '') {
                $this->settings['api_key'] = $legacy;
            }
        }
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'section_advanced' => [
                'title' => __('Advanced (manual configuration)', 'octavawms'),
                'type' => 'title',
            ],
            'api_base' => [
                'title' => __('API base URL (override)', 'octavawms'),
                'type' => 'text',
                'description' => __(
                    'Optional. Scheme and hostname only (e.g. https://pro.oawms.com or https://alpha.orderadmin.eu). When set, all REST, OAuth, connect, and label requests use this host. Leave empty for the cloud default or to follow the hostname from Label endpoint after connect.',
                    'octavawms'
                ),
                'desc_tip' => true,
                'placeholder' => 'https://pro.oawms.com',
                'default' => '',
            ],
            'api_key' => [
                'title' => __('API key (Bearer token)', 'octavawms'),
                'type' => 'password',
                'description' => __('Set automatically after you connect. You can also paste it manually.', 'octavawms'),
                'desc_tip' => true,
                'default' => '',
            ],
            'sync_new_orders' => [
                'title' => __('Auto-sync new orders', 'octavawms'),
                'type' => 'checkbox',
                'label' => sprintf(
                    /* translators: %s: service name (e.g. OctavaWMS, Изпрати.БГ). */
                    __('Send new orders to %s automatically', 'octavawms'),
                    UiBranding::serviceName()
                ),
                'default' => 'yes',
            ],
            'sync_order_updates' => [
                'title' => __('Auto-sync order updates', 'octavawms'),
                'type' => 'checkbox',
                'label' => __('Re-import orders when they are updated (debounced)', 'octavawms'),
                'default' => 'yes',
            ],
        ];
    }

    public function getConnectDescriptionHtml(): string
    {
        $ak = (string) $this->get_option('api_key', '');

        $connected = $ak !== '';
        $statusClass = $connected ? 'octavawms-badge--ok' : 'octavawms-badge--off';
        $statusText = $connected
            ? esc_html(sprintf(
                /* translators: %s: service name (e.g. OctavaWMS, Изпрати.БГ). */
                __('Connected to %s', 'octavawms'),
                UiBranding::serviceName()
            ))
            : esc_html__('Not connected', 'octavawms');

        ob_start();
        ?>
        <div class="octavawms-connect" style="max-width:640px">
            <p>
                <span class="octavawms-badge <?php echo esc_attr($statusClass); ?>"
                      id="octavawms-status-badge"
                      style="display:inline-block;padding:4px 10px;border-radius:4px;font-weight:600;<?php echo $connected ? 'background:#e7f4e4;color:#1e4620;' : 'background:#f0f0f0;color:#333;'; ?>">
                    <?php echo esc_html($statusText); ?>
                </span>
            </p>
            <p>
                <button type="button" class="button button-primary" id="octavawms-connect-btn">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: service name (e.g. OctavaWMS, Изпрати.БГ). */
                        __('Connect to %s', 'octavawms'),
                        UiBranding::serviceName()
                    ));
                    ?>
                </button>
                <button type="button" class="button button-secondary" id="octavawms-panel-login-btn">
                    <?php esc_html_e('Login to the panel', 'octavawms'); ?>
                </button>
                <span class="spinner" id="octavawms-connect-spinner" style="float:none;visibility:hidden"></span>
            </p>
            <p class="description" id="octavawms-connect-message" style="min-height:1.5em" aria-live="polite"></p>
        </div>
        <hr>
        <p class="description"><?php esc_html_e('Advanced: you can paste the API key manually below if needed.', 'octavawms'); ?></p>
        <?php
        return (string) ob_get_clean();
    }

    public function process_admin_options(): void
    {
        parent::process_admin_options();

        if (! is_array($this->settings)) {
            $this->init_settings();
        }
        if (! is_array($this->settings)) {
            return;
        }
        if (isset($this->settings['api_key'])) {
            update_option(Options::LEGACY_API_KEY, (string) $this->settings['api_key']);
        }
    }

    public function admin_options(): void
    {
        echo $this->getConnectDescriptionHtml();
        parent::admin_options();
    }
}
