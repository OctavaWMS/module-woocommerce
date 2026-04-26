<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

class Options
{
    public const LEGACY_LABEL_ENDPOINT = 'octavawms_label_endpoint';
    public const LEGACY_API_KEY = 'octavawms_api_key';

    public const INTEGRATION_ID = 'octavawms';

    /**
     * @return string[]
     */
    private static function integrationSettingsOptionNames(): array
    {
        return [
            'woocommerce_' . self::INTEGRATION_ID . '_settings',
        ];
    }

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

}
