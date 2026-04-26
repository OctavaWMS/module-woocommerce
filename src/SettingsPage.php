<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

class SettingsPage extends \WC_Integration
{
    public function __construct()
    {
        $this->id = Options::INTEGRATION_ID;
        $this->method_title = __('OctavaWMS Connector', 'octavawms');
        $this->method_description = __(
            'Connect your store to OctavaWMS. At minimum this handles shipping label requests; you can set the label endpoint and API key manually in Advanced, or add more as we extend the plugin.',
            'octavawms'
        );

        $this->has_fields = true;

        parent::__construct();
    }

    public function init_settings(): void
    {
        parent::init_settings();

        if (! is_array($this->settings)) {
            return;
        }

        if (empty($this->settings['label_endpoint'])) {
            $legacy = (string) get_option(Options::LEGACY_LABEL_ENDPOINT, '');
            if ($legacy !== '') {
                $this->settings['label_endpoint'] = $legacy;
            }
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
            'connect_url' => [
                'title' => __('Connect service URL (optional)', 'octavawms'),
                'type' => 'url',
                'description' => __(
                    'Override the default one-click connect URL. Leave empty to use the default OctavaWMS service.',
                    'octavawms'
                ),
                'default' => '',
            ],
            'label_endpoint' => [
                'title' => __('Label endpoint URL', 'octavawms'),
                'type' => 'url',
                'description' => __(
                    'URL that accepts POST for label requests (set automatically after you connect, or from OctavaWMS / self-hosted).',
                    'octavawms'
                ),
                'desc_tip' => true,
                'default' => '',
                'placeholder' => 'https://',
            ],
            'api_key' => [
                'title' => __('API key (Bearer token)', 'octavawms'),
                'type' => 'password',
                'description' => __('Optional. Leave empty only if the label endpoint is public.', 'octavawms'),
                'desc_tip' => true,
                'default' => '',
            ],
        ];
    }

    public function getConnectDescriptionHtml(): string
    {
        $ep = (string) $this->get_option('label_endpoint', '');
        $ak = (string) $this->get_option('api_key', '');

        $connected = $ep !== '' && $ak !== '';
        if ($ep !== '' && $ak === '') {
            $connected = true;
        }

        $statusClass = $connected ? 'octavawms-badge--ok' : 'octavawms-badge--off';
        $statusText = $connected
            ? esc_html__('Connected to OctavaWMS', 'octavawms')
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
                    <?php esc_html_e('Connect to OctavaWMS', 'octavawms'); ?>
                </button>
                <span class="spinner" id="octavawms-connect-spinner" style="float:none;visibility:hidden"></span>
            </p>
            <p class="description" id="octavawms-connect-message" style="min-height:1.5em" aria-live="polite"></p>
        </div>
        <hr>
        <p class="description"><?php esc_html_e('Advanced: you can set the label endpoint and API key manually below.', 'octavawms'); ?></p>
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
        if (isset($this->settings['label_endpoint'], $this->settings['api_key'])) {
            update_option(Options::LEGACY_LABEL_ENDPOINT, (string) $this->settings['label_endpoint']);
            update_option(Options::LEGACY_API_KEY, (string) $this->settings['api_key']);
        }
    }

    public function admin_options(): void
    {
        echo $this->getConnectDescriptionHtml();
        parent::admin_options();
    }
}
