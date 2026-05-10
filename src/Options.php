<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

class Options
{
    public const LEGACY_LABEL_ENDPOINT = 'octavawms_label_endpoint';
    public const LEGACY_API_KEY = 'octavawms_api_key';

    public const INTEGRATION_ID = 'octavawms';

    /** Default OctavaWMS API host (no trailing slash). */
    public const DEFAULT_API_BASE = 'https://pro.oawms.com';

    public static function getLabelEndpoint(): string
    {
        foreach (self::integrationSettingsOptionNames() as $name) {
            $settings = (array) get_option($name, []);
            if (! empty($settings['label_endpoint']) && is_string($settings['label_endpoint'])) {
                return (string) $settings['label_endpoint'];
            }
        }

        return (string) get_option(self::LEGACY_LABEL_ENDPOINT, '');
    }

    public static function getRefreshToken(): string
    {
        $settings = (array) get_option('woocommerce_' . self::INTEGRATION_ID . '_settings', []);
        $t = $settings['refresh_token'] ?? '';

        return is_string($t) ? trim($t) : '';
    }

    public static function getOAuthDomain(): string
    {
        $settings = (array) get_option('woocommerce_' . self::INTEGRATION_ID . '_settings', []);
        $d = $settings['oauth_domain'] ?? '';

        return is_string($d) ? trim($d) : '';
    }

    /**
     * @return string[]
     */
    private static function integrationSettingsOptionNames(): array
    {
        return [
            'woocommerce_' . self::INTEGRATION_ID . '_settings',
        ];
    }

    /**
     * Resolve the base URL for all API calls.
     *
     * Priority:
     *   1. Integration setting **API base URL (override)** (`api_base`) — scheme://host only.
     *   2. Host from stored `label_endpoint` (after connect / legacy).
     *   3. {@see DEFAULT_API_BASE}.
     */
    public static function getBaseUrl(): string
    {
        $settings = (array) get_option('woocommerce_' . self::INTEGRATION_ID . '_settings', []);

        $override = isset($settings['api_base']) && is_string($settings['api_base'])
            ? self::normalizedApiBaseFromUserInput(trim($settings['api_base']))
            : '';
        if ($override !== '') {
            return $override;
        }

        $labelEndpoint = isset($settings['label_endpoint']) && is_string($settings['label_endpoint']) ? trim($settings['label_endpoint']) : '';
        if ($labelEndpoint === '') {
            $labelEndpoint = (string) get_option(self::LEGACY_LABEL_ENDPOINT, '');
        }
        if ($labelEndpoint !== '') {
            $base = self::extractBaseFromUrl($labelEndpoint, null);
            if ($base !== '') {
                return $base;
            }
        }

        return rtrim(self::DEFAULT_API_BASE, '/');
    }

    /** Normalize **API base** field: scheme://host only, or '' if invalid / empty. */
    private static function normalizedApiBaseFromUserInput(string $trimmed): string
    {
        if ($trimmed === '') {
            return '';
        }

        return self::extractBaseFromUrl($trimmed, null);
    }

    /**
     * Extract scheme://host from a URL, optionally stripping a known path suffix first.
     */
    private static function extractBaseFromUrl(string $url, ?string $stripSuffix): string
    {
        $url = rtrim($url, '/');
        if ($stripSuffix !== null && str_ends_with($url, $stripSuffix)) {
            $url = substr($url, 0, -strlen($stripSuffix));
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $s = is_string($scheme) && $scheme !== '' ? $scheme : 'https';

            return $s . '://' . $host;
        }

        return '';
    }

    public static function getApiKey(): string
    {
        foreach (self::integrationSettingsOptionNames() as $name) {
            $settings = (array) get_option($name, []);
            if (isset($settings['api_key']) && is_string($settings['api_key']) && (string) $settings['api_key'] !== '') {
                return (string) $settings['api_key'];
            }
        }

        return (string) get_option(self::LEGACY_API_KEY, '');
    }

    public static function getSourceId(): int
    {
        foreach (self::integrationSettingsOptionNames() as $name) {
            $settings = (array) get_option($name, []);
            if (isset($settings['source_id']) && (string) $settings['source_id'] !== '') {
                return (int) $settings['source_id'];
            }
        }

        return 0;
    }

    public static function isNewOrderSyncEnabled(): bool
    {
        $settings = (array) get_option('woocommerce_' . self::INTEGRATION_ID . '_settings', []);
        $v = $settings['sync_new_orders'] ?? null;

        return ! (is_string($v) && $v === 'no');
    }

    public static function isOrderUpdateSyncEnabled(): bool
    {
        $settings = (array) get_option('woocommerce_' . self::INTEGRATION_ID . '_settings', []);
        $v = $settings['sync_order_updates'] ?? null;

        return ! (is_string($v) && $v === 'no');
    }

    public static function isImportAsyncEnabled(): bool
    {
        $settings = (array) get_option('woocommerce_' . self::INTEGRATION_ID . '_settings', []);
        $v = $settings['import_async'] ?? null;

        return ! (is_string($v) && $v === 'no');
    }

    public static function saveCredentials(string $apiKey, string $labelEndpoint, int $sourceId): void
    {
        update_option(self::LEGACY_LABEL_ENDPOINT, $labelEndpoint);
        update_option(self::LEGACY_API_KEY, $apiKey);

        $name = 'woocommerce_' . self::INTEGRATION_ID . '_settings';
        $settings = (array) get_option($name, []);
        if (! is_array($settings)) {
            $settings = [];
        }
        $settings['label_endpoint'] = $labelEndpoint;
        $settings['api_key'] = $apiKey;
        if ($sourceId > 0) {
            $settings['source_id'] = (string) $sourceId;
        }
        unset($settings['refresh_token'], $settings['oauth_domain']);
        update_option($name, $settings);
    }

    /**
     * Store refresh + domain from POST /connect; clears bearer until OAuth exchange succeeds.
     */
    public static function saveOAuthBootstrap(string $refreshToken, string $oauthDomain, string $labelEndpoint, int $sourceId): void
    {
        update_option(self::LEGACY_LABEL_ENDPOINT, $labelEndpoint);
        update_option(self::LEGACY_API_KEY, '');

        $name = 'woocommerce_' . self::INTEGRATION_ID . '_settings';
        $settings = (array) get_option($name, []);
        if (! is_array($settings)) {
            $settings = [];
        }
        $settings['refresh_token'] = $refreshToken;
        $settings['oauth_domain'] = $oauthDomain;
        $settings['label_endpoint'] = $labelEndpoint;
        $settings['api_key'] = '';
        if ($sourceId > 0) {
            $settings['source_id'] = (string) $sourceId;
        }
        update_option($name, $settings);
    }

    public static function mergeAccessTokenFromOAuth(string $accessToken, ?string $rotatedRefreshToken): void
    {
        $name = 'woocommerce_' . self::INTEGRATION_ID . '_settings';
        $settings = (array) get_option($name, []);
        if (! is_array($settings)) {
            $settings = [];
        }
        $settings['api_key'] = $accessToken;
        if ($rotatedRefreshToken !== null && $rotatedRefreshToken !== '') {
            $settings['refresh_token'] = $rotatedRefreshToken;
        }
        update_option($name, $settings);
        update_option(self::LEGACY_API_KEY, $accessToken);
    }
}
