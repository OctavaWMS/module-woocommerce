<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Admin\SettingsAjax;
use OctavaWMS\WooCommerce\Api\BackendApiClient;

class SettingsPage extends \WC_Integration
{
    public function __construct()
    {
        $this->id = Options::INTEGRATION_ID;
        $this->method_title = UiBranding::integrationTitle();
        $this->method_description = __(
            'Connect your store to OctavaWMS for shipping label generation and order management.',
            'octavawms'
        );

        // WC_Settings_API / WC_Integration do not declare __construct(); calling parent::__construct() fatal-errors on PHP.
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_integration_' . $this->id, [$this, 'process_admin_options']);
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
                'label' => __('Send new orders to OctavaWMS automatically', 'octavawms'),
                'default' => 'yes',
            ],
            'sync_order_updates' => [
                'title' => __('Auto-sync order updates', 'octavawms'),
                'type' => 'checkbox',
                'label' => __('Re-import orders when they are updated (debounced)', 'octavawms'),
                'default' => 'yes',
            ],
            'import_async' => [
                'title' => __('Async import', 'octavawms'),
                'type' => 'checkbox',
                'label' => __(
                    'Run OctavaWMS import asynchronously (recommended; avoids long HTTP waits and timeouts)',
                    'octavawms'
                ),
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
        $beforeImportAsync = Options::isImportAsyncEnabled();
        parent::process_admin_options();
        $postedCarrierMapping = $this->savePostedCarrierMappingJson();

        if (! is_array($this->settings)) {
            $this->init_settings();
        }
        if (! is_array($this->settings)) {
            return;
        }
        if (isset($this->settings['api_key'])) {
            update_option(Options::LEGACY_API_KEY, (string) $this->settings['api_key']);
        }
        if ($postedCarrierMapping !== null) {
            $this->syncCarrierMappingToIntegrationSource($postedCarrierMapping);
        }

        $afterImportAsync = Options::isImportAsyncEnabled();
        if ($beforeImportAsync === $afterImportAsync) {
            return;
        }

        $sourceId = Options::getSourceId();
        if ($sourceId <= 0 || Options::getApiKey() === '') {
            return;
        }

        $result = IntegrationSourceImportAsyncSync::syncImportAsyncSetting(
            new BackendApiClient(),
            $sourceId,
            $afterImportAsync
        );
        if (! $result['ok'] && $result['message'] !== '') {
            if (class_exists(\WC_Admin_Settings::class, false)) {
                \WC_Admin_Settings::add_error($result['message']);
            } else {
                add_settings_error('general', 'octavawms_import_async_sync', $result['message'], 'error');
            }
        }
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function savePostedCarrierMappingJson(): ?array
    {
        $postKey = 'woocommerce_' . $this->id . '_' . Options::CARRIER_MAPPING_JSON;
        if (! isset($_POST[$postKey])) {
            return null;
        }

        $raw = function_exists('wp_unslash')
            ? wp_unslash((string) $_POST[$postKey])
            : (string) $_POST[$postKey];
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ($decoded !== [] && ! array_is_list($decoded))) {
            $this->addAdminError(__('Carrier mapping must be a JSON array.', 'octavawms'));

            return null;
        }

        $nonEmpty = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $k = isset($row['courierMetaKey']) && is_string($row['courierMetaKey'])
                ? trim($row['courierMetaKey'])
                : '';
            $v = isset($row['courierMetaValue']) && is_string($row['courierMetaValue'])
                ? trim($row['courierMetaValue'])
                : '';
            if ($k !== '' && $v !== '') {
                $nonEmpty[] = $row;
            }
        }

        $normalized = SettingsAjax::validateAndNormalizeRows($nonEmpty);
        if ($normalized === null) {
            $this->addAdminError(__('Invalid carrier mapping row(s). Check meta key, meta value, type, carrier, and rate.', 'octavawms'));

            return null;
        }

        $json = SettingsAjax::encodeCarrierMappingRows($normalized);
        Options::saveCarrierMappingJson($json);
        $this->settings[Options::CARRIER_MAPPING_JSON] = $json;

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $carrierMapping
     */
    private function syncCarrierMappingToIntegrationSource(array $carrierMapping): void
    {
        $sourceId = Options::getSourceId();
        if ($sourceId <= 0 || Options::getApiKey() === '') {
            return;
        }

        $apiClient = $this->createBackendApiClient();
        $source = $apiClient->getIntegrationSource($sourceId);
        if ($source === null) {
            $this->addAdminError(__('Carrier mapping was saved in WordPress, but OctavaWMS integration source could not be loaded.', 'octavawms'));

            return;
        }

        $settings = is_array($source['settings'] ?? null) ? $source['settings'] : [];
        $settings = SettingsAjax::mergeCarrierMappingIntoSettings($settings, $carrierMapping);
        $patch = $apiClient->patchIntegrationSource($sourceId, ['settings' => $settings]);
        if (! $patch['ok']) {
            $this->addAdminError($this->carrierMappingPatchFailureMessage($patch));
        }
    }

    /**
     * @param array{ok: bool, status: int, data: mixed, raw: string, response_headers: array<string, string>} $patch
     */
    private function carrierMappingPatchFailureMessage(array $patch): string
    {
        if (is_array($patch['data'])) {
            foreach (['detail', 'message', 'title', 'error'] as $key) {
                if (isset($patch['data'][$key]) && is_string($patch['data'][$key]) && trim($patch['data'][$key]) !== '') {
                    return sprintf(
                        /* translators: %s backend error. */
                        __('Carrier mapping was saved in WordPress, but OctavaWMS sync failed: %s', 'octavawms'),
                        trim($patch['data'][$key])
                    );
                }
            }
        }

        $raw = trim((string) $patch['raw']);
        if ($raw !== '') {
            return sprintf(
                /* translators: %s backend response excerpt. */
                __('Carrier mapping was saved in WordPress, but OctavaWMS sync failed: %s', 'octavawms'),
                mb_substr($raw, 0, 300)
            );
        }

        return __('Carrier mapping was saved in WordPress, but OctavaWMS sync failed.', 'octavawms');
    }

    private function addAdminError(string $message): void
    {
        if (class_exists(\WC_Admin_Settings::class, false)) {
            \WC_Admin_Settings::add_error($message);
        } else {
            add_settings_error('general', 'octavawms_settings_error', $message, 'error');
        }
    }

    protected function createBackendApiClient(): BackendApiClient
    {
        return new BackendApiClient();
    }

    public function admin_options(): void
    {
        echo $this->getConnectDescriptionHtml();
        parent::admin_options();
        echo $this->getCarrierMatrixSectionHtml();
    }

    private function getCarrierMatrixSectionHtml(): string
    {
        if (! current_user_can('manage_woocommerce')) {
            return '';
        }

        ob_start();
        ?>
        <div id="octavawms-carrier-matrix-root" class="octavawms-carrier-matrix" style="margin:1.25em 0 2em;max-width:1280px;">
            <h2 style="font-size:1.1em;margin-bottom:0.5em;">
                <?php esc_html_e('Carrier meta mapping (Woo → Octava)', 'octavawms'); ?>
            </h2>
            <p class="description" style="max-width:960px;">
                <?php esc_html_e(
                    'Map WooCommerce order meta (e.g. courierName, courierID) and optional delivery_type to a carrier service, rate, and pickup strategy. Saved to your OctavaWMS integration source (same as Orderadmin settings).',
                    'octavawms'
                ); ?>
            </p>
            <p>
                <button type="button" class="button" id="octavawms-matrix-toggle-mode">
                    <?php esc_html_e('Switch to JSON', 'octavawms'); ?>
                </button>
                <button type="button" class="button button-primary" id="octavawms-matrix-save" style="margin-left:8px;">
                    <?php esc_html_e('Save mapping', 'octavawms'); ?>
                </button>
                <button type="button" class="button" id="octavawms-matrix-add-row" style="margin-left:8px;">
                    <?php esc_html_e('Add row', 'octavawms'); ?>
                </button>
                <span class="spinner" id="octavawms-matrix-spinner" style="float:none;visibility:hidden;margin-left:8px;"></span>
            </p>
            <input type="hidden"
                   id="octavawms-carrier-mapping-json"
                   name="woocommerce_<?php echo esc_attr($this->id); ?>_<?php echo esc_attr(Options::CARRIER_MAPPING_JSON); ?>"
                   value="<?php echo esc_attr(Options::getCarrierMappingJson()); ?>">
            <p id="octavawms-matrix-message" class="description" style="min-height:1.25em;color:#b32d2d;" aria-live="polite"></p>
            <div id="octavawms-matrix-visual-wrap">
                <table class="widefat striped" id="octavawms-matrix-table" style="margin-top:0.5em;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WC meta key', 'octavawms'); ?></th>
                            <th><?php esc_html_e('WC meta value', 'octavawms'); ?></th>
                            <th><?php esc_html_e('WC delivery_type (optional)', 'octavawms'); ?></th>
                            <th><?php esc_html_e('Strategy for AI', 'octavawms'); ?></th>
                            <th><?php esc_html_e('Carrier', 'octavawms'); ?></th>
                            <th><?php esc_html_e('Rate', 'octavawms'); ?></th>
                            <th style="width:48px;"></th>
                        </tr>
                    </thead>
                    <tbody id="octavawms-matrix-tbody"></tbody>
                </table>
            </div>
            <div id="octavawms-matrix-json-wrap" style="display:none;">
                <label for="octavawms-matrix-json" class="screen-reader-text"><?php esc_html_e('JSON', 'octavawms'); ?></label>
                <textarea id="octavawms-matrix-json" rows="16" class="large-text code" style="width:100%;font-family:monospace;"></textarea>
            </div>
        </div>
        <hr>
        <?php

        return (string) ob_get_clean();
    }
}
