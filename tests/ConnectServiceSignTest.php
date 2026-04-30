<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\ConnectService;
use OctavaWMS\WooCommerce\Options;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class ConnectServiceSignTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testPrepareConnectHttpRequestAddsOctavaWmsHeaderWhenKeyFound(): void
    {
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            public function prepare(string $query, ...$args): string
            {
                return $query;
            }

            public function get_row(string $sql, string $output): ?array
            {
                return [
                    'consumer_secret' => 'cs_test_secret',
                    'truncated_key' => 'fc2aadf',
                    'description' => 'OctavaWMS - API (2026-04-26)',
                    'user_id' => 1,
                ];
            }
        };

        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v, JSON_THROW_ON_ERROR));

        $service = new ConnectService();
        $out = $service->prepareConnectHttpRequest([
            'siteUrl' => 'https://ironlogic.bg',
            'adminEmail' => 'm@ironlogic.bg',
            'storeName' => 'IronLogic BG',
        ]);

        self::assertSame('fc2aadf', $out['key_last7']);
        self::assertArrayHasKey('Authorization', $out['headers']);
        self::assertStringStartsWith('OctavaWMS key_last7=fc2aadf', $out['headers']['Authorization']);
        self::assertStringContainsString('algo=HMAC-SHA256', $out['headers']['Authorization']);
        self::assertStringContainsString('signature=', $out['headers']['Authorization']);
    }

    public function testPrepareConnectHttpRequestOmitsAuthorizationWhenNoKeyRow(): void
    {
        unset($GLOBALS['wpdb']);

        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v, JSON_THROW_ON_ERROR));

        $service = new ConnectService();
        $out = $service->prepareConnectHttpRequest([
            'siteUrl' => 'https://shop.test',
            'adminEmail' => 'a@b.com',
            'storeName' => 'S',
        ]);

        self::assertNull($out['key_last7']);
        self::assertArrayNotHasKey('Authorization', $out['headers']);
    }

    public function testSaveCredentialsStoresIntegrationSettings(): void
    {
        $stored = [];
        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$stored) {
            return $stored[$name] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $name, $value) use (&$stored): void {
            $stored[$name] = $value;
        });

        Options::saveCredentials('plugin-key-xyz', Options::DEFAULT_API_BASE . '/apps/woocommerce/api/label', 13259);

        $settings = $stored['woocommerce_octavawms_settings'] ?? [];
        self::assertSame('plugin-key-xyz', $settings['api_key'] ?? null);
        self::assertSame('13259', $settings['source_id'] ?? null);
        self::assertStringContainsString('/apps/woocommerce/api/label', (string) ($settings['label_endpoint'] ?? ''));
    }
}
