<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Admin\SettingsAjax;
use OctavaWMS\WooCommerce\Api\BackendApiClient;

class ConnectService
{
    public const ACTION = 'octavawms_connect';

    public const PANEL_LOGIN_ACTION = 'octavawms_panel_login_url';

    /** @see handleAjaxPanelLoginUrl() */
    public const PANEL_LOGIN_NONCE_ACTION = 'octavawms_panel_login';

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handleAjaxConnect']);
        add_action('wp_ajax_' . self::PANEL_LOGIN_ACTION, [$this, 'handleAjaxPanelLoginUrl']);
        // Priority 20: run after WooCommerce (priority 10) has registered selectWoo.
        add_action('admin_enqueue_scripts', [$this, 'maybeEnqueueConnectScript'], 20);
    }

    /**
     * Build JSON body + headers for POST /apps/woocommerce/connect (including optional HMAC auth).
     *
     * @param array{siteUrl:string, adminEmail:string, storeName:string} $body
     *
     * @return array{body_json: string, headers: array<string, string>, key_last7: string|null}
     */
    public function prepareConnectHttpRequest(array $body): array
    {
        $bodyJson = (string) wp_json_encode($body);
        $requestHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $keyLast7 = null;
        $creds = WooRestCredentials::findOctavawmsKey();
        if ($creds !== null) {
            $signed = WooRestCredentials::signConnectRequest($creds, $bodyJson);
            $requestHeaders['Authorization'] = $signed['header'];
            $keyLast7 = $signed['key_last7'];
        }

        return [
            'body_json' => $bodyJson,
            'headers' => $requestHeaders,
            'key_last7' => $keyLast7,
        ];
    }

    public function maybeEnqueueConnectScript(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        if (! isset($_GET['tab'], $_GET['section']) || $_GET['tab'] !== 'integration' || $_GET['section'] !== Options::INTEGRATION_ID) {
            return;
        }
        if (! function_exists('wp_enqueue_script')) {
            return;
        }

        $plugin_root = dirname(__DIR__);
        $script_rel = 'assets/js/admin-connect.js';
        $script_path = $plugin_root . '/' . $script_rel;
        $script_url = plugins_url($script_rel, $plugin_root . '/octavawms-woocommerce.php');
        $version = is_readable($script_path) ? (string) filemtime($script_path) : '1.0.0';

        wp_register_script('octavawms-admin-connect', $script_url, ['jquery'], $version, true);

        wp_localize_script('octavawms-admin-connect', 'octavawmsConnect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::ACTION),
            'panelLoginNonce' => wp_create_nonce(self::PANEL_LOGIN_NONCE_ACTION),
            'strings' => [
                'connected' => __('Connected to OctavaWMS', 'izprati-bulgaria-shipping'),
                'notConnected' => __('Not connected', 'izprati-bulgaria-shipping'),
                'error' => __('Connect request failed. Check your site can reach the OctavaWMS service.', 'izprati-bulgaria-shipping'),
                'panelLogin' => __('Login to the panel', 'izprati-bulgaria-shipping'),
                'panelLoginError' => __('Could not open Octava panel. Try connecting again or check logs.', 'izprati-bulgaria-shipping'),
            ],
        ]);

        wp_enqueue_script('octavawms-admin-connect');

        $matrixRel = 'assets/js/admin-settings-matrix.js';
        $matrixPath = $plugin_root . '/' . $matrixRel;
        $matrixUrl = plugins_url($matrixRel, $plugin_root . '/octavawms-woocommerce.php');
        $matrixVersion = is_readable($matrixPath) ? (string) filemtime($matrixPath) : '1.0.0';
        $matrixDeps = ['jquery'];
        wp_register_script(
            'octavawms-admin-settings-matrix',
            $matrixUrl,
            $matrixDeps,
            $matrixVersion,
            true
        );
        wp_localize_script('octavawms-admin-settings-matrix', 'octavawmsCarrierMatrix', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(SettingsAjax::ACTION),
            'action' => SettingsAjax::ACTION,
            'initialRows' => Options::getCarrierMappingRows(),
            'strings' => [
                'switchJson' => __('Switch to JSON', 'izprati-bulgaria-shipping'),
                'switchVisual' => __('Switch to Visual', 'izprati-bulgaria-shipping'),
                'loadFailed' => __('Could not load mapping.', 'izprati-bulgaria-shipping'),
                'invalidJson' => __('Invalid JSON. Fix errors before switching to Visual.', 'izprati-bulgaria-shipping'),
                'pickCarrier' => __('Search carrier…', 'izprati-bulgaria-shipping'),
                'pickRate' => __('Rate (optional)', 'izprati-bulgaria-shipping'),
                'anyRate' => __('— Any / none —', 'izprati-bulgaria-shipping'),
            ],
        ]);
        wp_localize_script('octavawms-admin-settings-matrix', 'octavawmsCodRules', [
            'initialRows' => Options::getCodVisibilityRules(),
            'strings' => [
                'invalidRules' => __('Invalid cash on delivery rules.', 'izprati-bulgaria-shipping'),
                'hideCod' => __('Hide COD', 'izprati-bulgaria-shipping'),
                'allowCod' => __('Allow COD', 'izprati-bulgaria-shipping'),
                'anyType' => __('Any', 'izprati-bulgaria-shipping'),
                'address' => __('Address', 'izprati-bulgaria-shipping'),
                'office' => __('Office', 'izprati-bulgaria-shipping'),
                'locker' => __('Locker', 'izprati-bulgaria-shipping'),
                'officeLocker' => __('Office or locker', 'izprati-bulgaria-shipping'),
            ],
        ]);
        wp_enqueue_script('octavawms-admin-settings-matrix');
    }

    public function handleAjaxConnect(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission to connect.', 'izprati-bulgaria-shipping')], 403);
        }

        check_ajax_referer(self::ACTION, 'security');

        if (! function_exists('home_url') || ! function_exists('get_bloginfo') || ! function_exists('get_option')) {
            wp_send_json_error(['message' => __('WordPress is not available.', 'izprati-bulgaria-shipping')], 500);
        }

        $url = (string) apply_filters(
            'octavawms_connect_url',
            $this->getConnectUrlFromForm(),
            (string) home_url()
        );
        if ($url === '') {
            wp_send_json_error(['message' => __('Connect service URL is not set.', 'izprati-bulgaria-shipping')], 400);
        }

        $body = [
            'siteUrl' => (string) home_url(),
            'adminEmail' => (string) get_option('admin_email', ''),
            'storeName' => (string) get_bloginfo('name', 'display'),
        ];
        if ($body['adminEmail'] === '' || $body['siteUrl'] === '') {
            wp_send_json_error(['message' => __('Site URL and admin email are required.', 'izprati-bulgaria-shipping')], 400);
        }

        if (! is_ssl() && ! in_array(
            (string) wp_parse_url($body['siteUrl'], PHP_URL_HOST),
            ['localhost', '127.0.0.1'],
            true
        ) && (bool) apply_filters('octavawms_require_https_for_connect', true, $url)) {
            wp_send_json_error(
                [
                    'message' => __(
                        'Store must use HTTPS (SSL) to connect, except on localhost. Enable SSL and try again.',
                        'izprati-bulgaria-shipping'
                    ),
                ],
                400
            );
        }

        $prepared = $this->prepareConnectHttpRequest($body);
        $bodyJson = $prepared['body_json'];
        $requestHeaders = $prepared['headers'];
        $keyLast7 = $prepared['key_last7'];

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 45,
                'headers' => $requestHeaders,
                'body' => $bodyJson,
            ]
        );

        if ($response instanceof \WP_Error) {
            PluginLog::log(
                'error',
                'connect',
                array_merge(
                    PluginLog::httpExchange('POST', $url, $requestHeaders, $body, $response),
                    ['signed_with_key_last7' => $keyLast7]
                )
            );
            wp_send_json_error(
                [
                    'message' => $response->get_error_message(),
                ],
                502
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            PluginLog::log(
                'error',
                'connect',
                array_merge(
                    PluginLog::httpExchange('POST', $url, $requestHeaders, $body, $response),
                    [
                        'signed_with_key_last7' => $keyLast7,
                        'parse_note' => 'body is not a JSON object',
                    ]
                )
            );
            wp_send_json_error(
                [
                    'message' => sprintf(
                        // translators: %d HTTP status, %s response excerpt.
                        __('Invalid response from connect service (HTTP %1$d).', 'izprati-bulgaria-shipping'),
                        $code
                    ) . ' ' . mb_substr($raw, 0, 200),
                ],
                502
            );
        }

        $apiClient = new BackendApiClient();
        if ($apiClient->ingestConnectResponseArray($data)) {
            wp_send_json_success(
                [
                    'connected' => true,
                    'message' => __('Connected. Your credentials are saved.', 'izprati-bulgaria-shipping'),
                    'api_key' => Options::getApiKey(),
                ]
            );
        }

        PluginLog::log(
            'error',
            'connect',
            array_merge(
                PluginLog::httpExchange('POST', $url, $requestHeaders, $body, $response),
                [
                    'signed_with_key_last7' => $keyLast7,
                    'response_status_field' => (string) ($data['status'] ?? ''),
                    'response_message' => (string) ($data['message'] ?? ''),
                    'has_api_key' => (string) ($data['apiKey'] ?? $data['api_key'] ?? '') !== '',
                    'has_refresh_token' => (string) ($data['refreshToken'] ?? $data['refresh_token'] ?? '') !== '',
                    'has_domain' => (string) ($data['domain'] ?? '') !== '',
                    'source_id' => (int) ($data['sourceId'] ?? $data['source_id'] ?? 0),
                ]
            )
        );

        $err = (string) ($data['message'] ?? __('Connection failed.', 'izprati-bulgaria-shipping'));
        wp_send_json_error(
            [
                'message' => $err,
            ],
            $code >= 400 && $code < 600 ? $code : 500
        );
    }

    public function handleAjaxPanelLoginUrl(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission to open the panel.', 'izprati-bulgaria-shipping')], 403);
        }

        check_ajax_referer(self::PANEL_LOGIN_NONCE_ACTION, 'security');

        $client = new BackendApiClient();
        $resolved = $client->getPanelLoginRefreshToken();
        if (! $resolved['ok']) {
            wp_send_json_error(
                [
                    'message' => $resolved['message'] !== ''
                        ? $resolved['message']
                        : __('Could not open Octava panel.', 'izprati-bulgaria-shipping'),
                ],
                400
            );
        }

        $base = rtrim((string) apply_filters('octavawms_panel_app_base', 'https://app.izprati.bg'), '/');
        $loginUrl = $base . '/#/login?refreshToken=' . rawurlencode($resolved['refresh_token']);

        wp_send_json_success(['loginUrl' => $loginUrl]);
    }

    public const CONNECT_PATH = '/apps/woocommerce/connect';

    private function getConnectUrlFromForm(): string
    {
        $base = rtrim(Options::getBaseUrl(), '/');
        $default = $base . self::CONNECT_PATH;

        return (string) apply_filters('octavawms_default_connect_url', $default);
    }
}
