<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

class ConnectService
{
    public const ACTION = 'octavawms_connect';

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handleAjaxConnect']);
        add_action('admin_enqueue_scripts', [$this, 'maybeEnqueueConnectScript']);
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

        wp_register_script('octavawms-admin-connect', false, ['jquery', 'wp-util'], (string) time(), true);
        wp_enqueue_script('octavawms-admin-connect');

        wp_add_inline_script(
            'octavawms-admin-connect',
            'jQuery(function($) {
  const btn = $("#octavawms-connect-btn");
  if (!btn.length) return;
  const sp = $("#octavawms-connect-spinner");
  const msg = $("#octavawms-connect-message");
  const badge = $("#octavawms-status-badge");
  const data = { action: "octavawms_connect", nonce: octavawmsConnect.nonce, security: octavawmsConnect.nonce };
  btn.on("click", function() {
    sp.css("visibility", "visible");
    msg.text("");
    btn.prop("disabled", true);
    $.post(octavawmsConnect.ajaxUrl, data, function(r) {
      if (r && r.success) {
        msg.text((r.data && r.data.message) || "");
        if (r.data && r.data.connected) {
          badge.text(octavawmsConnect.strings.connected);
          badge.css({ background: "#e7f4e4", color: "#1e4620" });
          const ep = (r.data.label_endpoint) || "";
          const kw = (r.data.api_key) || "";
          if (ep) { $("input[name*=\"label_endpoint\"]").val(ep).trigger("change"); }
          if (kw) { $("input[name*=\"api_key\"]").val(kw).trigger("change"); }
        }
      } else {
        msg.text((r && r.data && r.data.message) ? r.data.message : (octavawmsConnect.strings.error || "Error"));
        badge.text(octavawmsConnect.strings.notConnected);
        badge.css({ background: "#f0f0f0", color: "#333" });
      }
    }, "json").fail(function() {
      msg.text(octavawmsConnect.strings.error);
    }).always(function() {
      sp.css("visibility", "hidden");
      btn.prop("disabled", false);
    });
  });
});'
        );

        wp_localize_script('octavawms-admin-connect', 'octavawmsConnect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::ACTION),
            'strings' => [
                'connected' => __('Connected to OctavaWMS', 'octavawms'),
                'notConnected' => __('Not connected', 'octavawms'),
                'error' => __('Connect request failed. Check your site can reach the OctavaWMS service.', 'octavawms'),
            ],
        ]);
    }

    public function handleAjaxConnect(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission to connect.', 'octavawms')], 403);
        }

        check_ajax_referer(self::ACTION, 'security');

        if (! function_exists('home_url') || ! function_exists('get_bloginfo') || ! function_exists('get_option')) {
            wp_send_json_error(['message' => __('WordPress is not available.', 'octavawms')], 500);
        }

        $url = (string) apply_filters(
            'octavawms_connect_url',
            $this->getConnectUrlFromForm(),
            (string) home_url()
        );
        if ($url === '') {
            wp_send_json_error(['message' => __('Connect service URL is not set.', 'octavawms')], 400);
        }

        $body = [
            'siteUrl' => (string) home_url(),
            'adminEmail' => (string) get_option('admin_email', ''),
            'storeName' => (string) get_bloginfo('name', 'display'),
        ];
        if ($body['adminEmail'] === '' || $body['siteUrl'] === '') {
            wp_send_json_error(['message' => __('Site URL and admin email are required.', 'octavawms')], 400);
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
                        'octavawms'
                    ),
                ],
                400
            );
        }

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 45,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]
        );

        if ($response instanceof \WP_Error) {
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
            wp_send_json_error(
                [
                    'message' => sprintf(
                        // translators: %d HTTP status, %s response excerpt.
                        __('Invalid response from connect service (HTTP %1$d).', 'octavawms'),
                        $code
                    ) . ' ' . mb_substr($raw, 0, 200),
                ],
                502
            );
        }

        $apiKey = (string) ($data['apiKey'] ?? $data['api_key'] ?? '');
        $labelEndpoint = (string) ($data['labelEndpoint'] ?? $data['label_endpoint'] ?? '');
        $sourceId = (int) ($data['sourceId'] ?? $data['source_id'] ?? 0);

        if (($data['status'] ?? '') === 'ok' && $apiKey !== '' && $labelEndpoint !== '') {
            $this->storeCredentials($labelEndpoint, $apiKey, $sourceId);
            wp_send_json_success(
                [
                    'connected' => true,
                    'message' => __('Connected. Your credentials are saved. Click "Save" below if the form is open.', 'octavawms'),
                    'label_endpoint' => $labelEndpoint,
                    'api_key' => $apiKey,
                ]
            );
        }

        $err = (string) ($data['message'] ?? __('Connection failed.', 'octavawms'));
        wp_send_json_error(
            [
                'message' => $err,
            ],
            $code >= 400 && $code < 600 ? $code : 500
        );
    }

    private function getConnectUrlFromForm(): string
    {
        $o = (array) get_option('woocommerce_' . Options::INTEGRATION_ID . '_settings', []);
        if (! empty($o['connect_url']) && is_string($o['connect_url'])) {
            return (string) $o['connect_url'];
        }

        return (string) apply_filters('octavawms_default_connect_url', 'https://pro.oawms.com/apps/woocommerce/connect');
    }

    private function storeCredentials(string $labelEndpoint, string $apiKey, int $sourceId): void
    {
        update_option(Options::LEGACY_LABEL_ENDPOINT, $labelEndpoint);
        update_option(Options::LEGACY_API_KEY, $apiKey);

        $name = 'woocommerce_' . Options::INTEGRATION_ID . '_settings';
        $settings = (array) get_option($name, []);
        if (! is_array($settings)) {
            $settings = [];
        }
        $settings['label_endpoint'] = $labelEndpoint;
        $settings['api_key'] = $apiKey;
        if ($sourceId > 0) {
            $settings['source_id'] = (string) $sourceId;
        }
        update_option($name, $settings);
    }
}
