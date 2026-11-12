<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

/** Optional installation observations; never participates in stock or shipping decisions. */
final class InstallationReporting
{
    public const OPTION = 'octavawms_installation_reporting';
    public const HOOK = 'octavawms_installation_reporting_tick';
    private const ACTION = 'octavawms_installation_reporting_save';
    private const PAGE = 'octavawms_installation-reporting';
    private const ENDPOINT = 'https://account.tagontrack.app/api/v1/wordpress-installations';
    private const CONSENT = '2026-11-12';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::ACTION, [$this, 'save']);
        add_action('admin_init', [$this, 'observe']);
        add_action(self::HOOK, [$this, 'tick']);
    }

    public function menu(): void
    {
        add_options_page(__('Izprati installation reporting', 'izprati-bulgaria-shipping'), __('Izprati reporting', 'izprati-bulgaria-shipping'), 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $state = (array) get_option(self::OPTION, []);
        echo '<div class="wrap"><h1>' . esc_html__('Izprati installation reporting', 'izprati-bulgaria-shipping') . '</h1>';
        echo '<p>' . esc_html__('Optionally share this site URL, store name, software versions, language, store country/currency, connection status and last seen with AccountPortal. Contact email is optional and unverified. No password, user account or subscription is created. Local plugin features work without reporting.', 'izprati-bulgaria-shipping') . '</p>';
        echo '<p><a href="https://tagontrack.app/privacy">' . esc_html__('Privacy policy', 'izprati-bulgaria-shipping') . '</a> · <a href="https://account.tagontrack.app/register">' . esc_html__('Create or sign in to your account', 'izprati-bulgaria-shipping') . '</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<p><label><input type="checkbox" name="reporting_enabled" value="1" ' . checked(! empty($state['enabled']), true, false) . '> ' . esc_html__('Allow optional installation reporting', 'izprati-bulgaria-shipping') . '</label></p>';
        echo '<p><label>' . esc_html__('Contact email to share (optional)', 'izprati-bulgaria-shipping') . ' <input type="email" name="reporting_email" maxlength="254" value="' . esc_attr((string) ($state['email'] ?? '')) . '"></label></p>';
        submit_button(__('Save and retry delivery', 'izprati-bulgaria-shipping'));
        echo '</form><p>' . esc_html__('Reporting status:', 'izprati-bulgaria-shipping') . ' ' . esc_html(! empty($state['enabled']) ? __('Enabled', 'izprati-bulgaria-shipping') : __('Disabled', 'izprati-bulgaria-shipping'));
        echo ' · ' . esc_html__('Last acknowledgement:', 'izprati-bulgaria-shipping') . ' ' . esc_html((string) ($state['ackAt'] ?? '—'));
        echo ' · ' . esc_html__('Pending events:', 'izprati-bulgaria-shipping') . ' ' . esc_html((string) count($state['queue'] ?? []));
        echo ' · ' . esc_html((string) ($state['error'] ?? '')) . '</p>';
        echo '<p>' . esc_html__('Delivery retries automatically when WordPress cron runs. Quiet sites or disabled cron can delay reporting. Disabling stops observations and queues a minimal withdrawal notice; it does not delete inventory or disconnect shipping.', 'izprati-bulgaria-shipping') . '</p></div>';
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Administrator access required.', 'izprati-bulgaria-shipping'));
        }
        check_admin_referer(self::ACTION);
        $enabled = isset($_POST['reporting_enabled']) && is_string($_POST['reporting_enabled']) && wp_unslash($_POST['reporting_enabled']) === '1';
        $email = isset($_POST['reporting_email']) && is_string($_POST['reporting_email']) ? sanitize_email(wp_unslash($_POST['reporting_email'])) : '';
        if (! $this->configure($enabled, $email)) {
            wp_die(esc_html__('Reporting is busy delivering an event. Retry saving in a few seconds.', 'izprati-bulgaria-shipping'));
        }
        $this->tick();
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
        exit;
    }

    /** Serialize all queue changes and network delivery; never put a credential in URLs or logs. */
    private function locked(callable $work): bool
    {
        $lock = self::OPTION . '_lock';
        $expires = time() + 120;
        if (! add_option($lock, $expires, '', false)) {
            global $wpdb;
            $old = get_option($lock);
            if ((int) $old >= time()) {
                return false;
            }
            // Compare-and-delete avoids removing a lock acquired by another request.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, (string) $old));
            wp_cache_delete($lock, 'options');
            if (! add_option($lock, $expires, '', false)) {
                return false;
            }
        }
        try {
            $work();
        } finally {
            delete_option($lock);
        }
        return true;
    }

    private function siteUrl(): string
    {
        return rtrim((string) home_url('/'), '/');
    }

    private function identity(array $state): array
    {
        if (($state['siteUrl'] ?? '') !== $this->siteUrl()) {
            // A restored staging copy must not impersonate or overwrite the original site.
            $state = ['enabled' => ! empty($state['enabled']), 'email' => $state['email'] ?? ''];
        }
        if (empty($state['siteId'])) {
            $state += ['siteId' => wp_generate_uuid4(), 'credential' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'sequence' => 0, 'queue' => [], 'siteUrl' => $this->siteUrl(), 'consentAt' => gmdate('Y-m-d\TH:i:s\Z')];
        }
        return $state;
    }

    public function configure(bool $enabled, string $email = ''): bool
    {
        $saved = $this->locked(function () use ($enabled, $email): void {
            $state = (array) get_option(self::OPTION, []);
            if (isset($state['siteUrl']) && $state['siteUrl'] !== $this->siteUrl()) {
                $state = []; // A clone cannot withdraw the original site's consent.
            }
            $wasEnabled = ! empty($state['enabled']);
            if ($enabled) {
                $state = $this->identity($state);
                $state['enabled'] = true;
                $state['email'] = $email;
                $state['consentAt'] = gmdate('Y-m-d\TH:i:s\Z');
                if (! $wasEnabled || empty($state['registered'])) {
                    // An unacknowledged registration must retain its exact event across retries.
                    if (empty($state['queue']) || end($state['queue'])['eventName'] === 'withdrawn') {
                        $state = $this->enqueue($state, 'registered');
                    }
                } elseif (empty($state['queue'])) {
                    $state = $this->enqueue($state, 'heartbeat');
                }
            } elseif ($wasEnabled) {
                $state['enabled'] = false;
                $state['email'] = '';
                $state['queue'] = [];
                // A first request may have committed despite an ambiguous HTTP response.
                $state = $this->enqueue($state, 'withdrawn');
            }
            $state['nextAttempt'] = 0;
            update_option(self::OPTION, $state, false);
        });
        $this->schedule();
        return $saved;
    }

    private function version(): string
    {
        return (string) (get_file_data(dirname(__DIR__) . '/octavawms-woocommerce.php', ['version' => 'Version'])['version'] ?? 'unknown');
    }

    private function snapshot(): array
    {
        global $wp_version;
        return ['plugin' => $this->version(), 'wordpress' => (string) $wp_version, 'woocommerce' => defined('WC_VERSION') ? WC_VERSION : '', 'php' => PHP_VERSION,
            'locale' => (string) get_locale(), 'country' => substr((string) get_option('woocommerce_default_country', ''), 0, 2), 'currency' => (string) get_option('woocommerce_currency', '')];
    }

    private function connected(): bool
    {
        return Options::getApiKey() !== "";
    }

    private function enqueue(array $state, string $event): array
    {
        $state['sequence'] = (int) ($state['sequence'] ?? 0) + 1;
        $withdrawn = $event === 'withdrawn';
        $payload = ['schemaVersion' => 1, 'product' => 'izprati-shipping', 'siteId' => $state['siteId'], 'eventId' => wp_generate_uuid4(), 'sequence' => $state['sequence'], 'eventName' => $event,
            'siteUrl' => $state['siteUrl'], 'storeName' => $withdrawn ? 'Reporting withdrawn' : mb_substr((string) get_bloginfo('name'), 0, 200),
            'contactEmail' => $withdrawn || empty($state['email']) ? null : $state['email'], 'consentVersion' => self::CONSENT, 'consentAt' => $state['consentAt'],
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'), 'connected' => ! $withdrawn && $this->connected(), 'environment' => $withdrawn ? ['plugin' => '', 'wordpress' => '', 'woocommerce' => '', 'php' => '', 'locale' => '', 'country' => '', 'currency' => ''] : $this->snapshot()];
        $state['queue'][] = $payload;
        $state['lastQueued'] = time();
        $state['observedVersion'] = $this->version();
        $state['observedConnected'] = $payload['connected'];
        return $state;
    }

    public function schedule(): void
    {
        $state = (array) get_option(self::OPTION, []);
        if (! empty($state['enabled']) || ! empty($state['queue'])) {
            $at = max(time() + 60, (int) ($state['nextAttempt'] ?? 0));
            $scheduled = wp_next_scheduled(self::HOOK);
            if (! $scheduled || $scheduled > $at) {
                if ($scheduled) {
                    wp_clear_scheduled_hook(self::HOOK);
                }
                wp_schedule_single_event($at, self::HOOK);
            }
        }
    }

    public function observe(): void
    {
        $this->tick(false); // Admin observations only queue; cron or manual retry performs HTTP.
    }

    public function tick(bool $deliver = true): void
    {
        $this->locked(function () use ($deliver): void {
            $state = (array) get_option(self::OPTION, []);
            if (empty($state['enabled']) && empty($state['queue'])) {
                return;
            }
            if (isset($state['siteUrl']) && $state['siteUrl'] !== $this->siteUrl()) {
                update_option(self::OPTION, ['enabled' => false, 'queue' => []], false);
                return; // Require fresh consent for a moved or cloned site.
            }
            $beforeSequence = (int) ($state['sequence'] ?? 0);
            if (! empty($state['enabled'])) {
                $state = $this->identity($state);
                if (empty($state['queue']) && empty($state['registered'])) {
                    $state = $this->enqueue($state, 'registered');
                } elseif (empty($state['queue']) && (($state['observedConnected'] ?? false) !== $this->connected())) {
                    $state = $this->enqueue($state, $this->connected() ? 'connected' : 'heartbeat');
                } elseif (empty($state['queue']) && (time() - (int) ($state['lastQueued'] ?? 0) >= DAY_IN_SECONDS || ($state['observedVersion'] ?? '') !== $this->version())) {
                    $state = $this->enqueue($state, 'heartbeat');
                }
            }
            if ((int) ($state['sequence'] ?? 0) > $beforeSequence) {
                $state['nextAttempt'] = 0;
            }
            update_option(self::OPTION, $state, false); // Durable BEFORE HTTP.
            if (! $deliver || empty($state['queue']) || (int) ($state['nextAttempt'] ?? 0) > time()) {
                return;
            }
            $event = $state['queue'][0];
            $response = wp_remote_post(self::ENDPOINT, ['timeout' => 10, 'redirection' => 0, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $state['credential']], 'body' => wp_json_encode($event)]);
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
            if ($code >= 200 && $code < 300 && is_array($body) && ($body['ok'] ?? false) === true && ($body['eventId'] ?? '') === $event['eventId']) {
                array_shift($state['queue']);
                $state['registered'] = $event['eventName'] !== 'withdrawn';
                $state['ackAt'] = gmdate('Y-m-d\TH:i:s\Z');
                $state['error'] = '';
                $state['attempts'] = 0;
                $state['nextAttempt'] = time() + (empty($state['queue']) && (! $state['enabled'] || $state['registered']) ? DAY_IN_SECONDS : 60);
            } else {
                // A withdrawal for a never-received registration has nothing to revoke centrally.
                if ($event['eventName'] === 'withdrawn' && $code === 409) {
                    $state['queue'] = [];
                    $state['registered'] = false;
                    $state['error'] = '';
                    update_option(self::OPTION, $state, false);
                    return;
                }
                $state['attempts'] = min(12, (int) ($state['attempts'] ?? 0) + 1);
                $state['nextAttempt'] = time() + min(DAY_IN_SECONDS, 60 * (2 ** $state['attempts']));
                $state['error'] = __('Delivery pending. Check outbound HTTPS and retry.', 'izprati-bulgaria-shipping');
            }
            update_option(self::OPTION, $state, false);
        });
        $this->schedule();
    }
}
