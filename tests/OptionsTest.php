<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Options;

final class OptionsTest extends TestCase
{
    public function testGetBaseUrlReturnsDefaultWhenNothingConfigured(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        self::assertSame(Options::DEFAULT_API_BASE, Options::getBaseUrl());
    }

    public function testGetBaseUrlFallsBackToLegacyLabelEndpoint(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['label_endpoint' => 'https://legacy.example.com/apps/woocommerce/api/label'];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        self::assertSame('https://legacy.example.com', Options::getBaseUrl());
    }

    public function testGetBaseUrlIgnoresStaleConnectUrlInSettings(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'connect_url' => 'https://should-not-be-used.example.com',
                    'label_endpoint' => '',
                ];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        self::assertSame(Options::DEFAULT_API_BASE, Options::getBaseUrl());
    }

    public function testGetSourceIdReturnsZeroWhenMissing(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [];
            }

            return $default;
        });

        self::assertSame(0, Options::getSourceId());
    }

    public function testGetSourceIdReturnsStoredInt(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['source_id' => '42'];
            }

            return $default;
        });

        self::assertSame(42, Options::getSourceId());
    }

    public function testGetBaseUrlUsesApiBaseOverrideBeforeLabelEndpoint(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'api_base' => 'https://custom.example.org',
                    'label_endpoint' => 'https://legacy.example.com/apps/woocommerce/api/label',
                ];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        self::assertSame('https://custom.example.org', Options::getBaseUrl());
    }

    public function testDefaultApiBaseConstant(): void
    {
        self::assertSame('https://pro.oawms.com', Options::DEFAULT_API_BASE);
    }

    public function testGetRefreshTokenAndOAuthDomainReturnEmptyWhenMissing(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [];
            }

            return $default;
        });

        self::assertSame('', Options::getRefreshToken());
        self::assertSame('', Options::getOAuthDomain());
    }

    public function testSaveOAuthBootstrapStoresRefreshClearsApiKeyAndLabelEndpoint(): void
    {
        $stored = [];
        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$stored) {
            return $stored[$name] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $name, $value) use (&$stored): void {
            $stored[$name] = $value;
        });

        $label = Options::DEFAULT_API_BASE . '/apps/woocommerce/api/label';
        Options::saveOAuthBootstrap('rt-secret', 'mytenant', $label, 99);

        $settings = $stored['woocommerce_octavawms_settings'] ?? [];
        self::assertSame('rt-secret', $settings['refresh_token'] ?? null);
        self::assertSame('mytenant', $settings['oauth_domain'] ?? null);
        self::assertSame('', $settings['api_key'] ?? null);
        self::assertSame('99', $settings['source_id'] ?? null);
        self::assertSame($label, $settings['label_endpoint'] ?? null);
        self::assertSame('', $stored[Options::LEGACY_API_KEY] ?? null);
        self::assertSame($label, $stored[Options::LEGACY_LABEL_ENDPOINT] ?? null);
    }

    public function testMergeAccessTokenFromOAuthSetsAccessAndRotatedRefresh(): void
    {
        $stored = [
            'woocommerce_octavawms_settings' => [
                'refresh_token' => 'old-rt',
                'oauth_domain' => 'd',
                'label_endpoint' => 'https://x.example/label',
                'api_key' => '',
            ],
        ];
        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$stored) {
            return $stored[$name] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $name, $value) use (&$stored): void {
            $stored[$name] = $value;
        });

        Options::mergeAccessTokenFromOAuth('access-jwt-1', 'new-rt');

        $settings = $stored['woocommerce_octavawms_settings'] ?? [];
        self::assertSame('access-jwt-1', $settings['api_key'] ?? null);
        self::assertSame('new-rt', $settings['refresh_token'] ?? null);
        self::assertSame('https://x.example/label', $settings['label_endpoint'] ?? null);
        self::assertSame('access-jwt-1', $stored[Options::LEGACY_API_KEY] ?? null);
    }

    public function testMergeAccessTokenFromOAuthKeepsRefreshWhenRotationNull(): void
    {
        $stored = [
            'woocommerce_octavawms_settings' => [
                'refresh_token' => 'keep-me',
                'oauth_domain' => 'd',
                'api_key' => '',
            ],
        ];
        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$stored) {
            return $stored[$name] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $name, $value) use (&$stored): void {
            $stored[$name] = $value;
        });

        Options::mergeAccessTokenFromOAuth('access-only', null);

        $settings = $stored['woocommerce_octavawms_settings'] ?? [];
        self::assertSame('access-only', $settings['api_key'] ?? null);
        self::assertSame('keep-me', $settings['refresh_token'] ?? null);
    }

    public function testOrderSyncFlagsDefaultEnabledWhenMissing(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [];
            }

            return $default;
        });

        self::assertTrue(Options::isNewOrderSyncEnabled());
        self::assertTrue(Options::isOrderUpdateSyncEnabled());
        self::assertTrue(Options::isImportAsyncEnabled());
    }

    public function testImportAsyncDisabledWhenNo(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['import_async' => 'no'];
            }

            return $default;
        });

        self::assertFalse(Options::isImportAsyncEnabled());
    }

    public function testOrderSyncFlagsRespectNo(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'sync_new_orders' => 'no',
                    'sync_order_updates' => 'no',
                ];
            }

            return $default;
        });

        self::assertFalse(Options::isNewOrderSyncEnabled());
        self::assertFalse(Options::isOrderUpdateSyncEnabled());
    }

    public function testCarrierMappingRowsDecodeLocalJson(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    Options::CARRIER_MAPPING_JSON => '[{"courierMetaKey":"courierName","courierMetaValue":"Speedy","deliveryService":23}]',
                ];
            }

            return $default;
        });

        $rows = Options::getCarrierMappingRows();

        self::assertCount(1, $rows);
        self::assertSame('Speedy', $rows[0]['courierMetaValue'] ?? null);
    }

}
