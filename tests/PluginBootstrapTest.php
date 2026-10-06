<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Options;

final class PluginBootstrapTest extends TestCase
{
    public function testMarketplaceCompatibilityHeadersAreDeclared(): void
    {
        $plugin = file_get_contents(dirname(__DIR__) . '/octavawms-woocommerce.php');
        self::assertIsString($plugin);
        self::assertStringContainsString(' * Tested up to: 7.1', $plugin);
        self::assertStringContainsString(' * WC tested up to: 11.1', $plugin);

        $readme = file_get_contents(dirname(__DIR__) . '/readme.txt');
        self::assertIsString($readme);
        self::assertStringContainsString("\nTested up to: 7.1\n", $readme);
    }

    public function testWordPressOrgReadmeDisclosesTheExternalService(): void
    {
        $readme = file_get_contents(dirname(__DIR__) . '/readme.txt');
        self::assertIsString($readme);
        self::assertStringContainsString('== External service and data disclosure ==', $readme);
        self::assertStringContainsString('https://api.izprati.bg/', $readme);
        self::assertStringContainsString('store URL, store name, and WordPress administrator email address', $readme);
        self::assertStringContainsString('billing and shipping names, addresses, email address, phone number', $readme);
        self::assertStringContainsString('https://izprati.bg/terms-conditions/', $readme);
        self::assertStringContainsString('https://izprati.bg/privacy-policy/', $readme);
    }

    public function testOptionsSaveCredentialsPersistsLikeBootstrap(): void
    {
        $stored = [];
        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$stored) {
            return $stored[$name] ?? $default;
        });
        Functions\when('update_option')->alias(static function (string $name, $value) use (&$stored): void {
            $stored[$name] = $value;
        });

        Options::saveCredentials('secret-key', 'https://pro.oawms.com/apps/woocommerce/api/label', 123);

        $settings = $stored['woocommerce_octavawms_settings'] ?? [];

        self::assertSame('https://pro.oawms.com/apps/woocommerce/api/label', $stored['octavawms_label_endpoint'] ?? null);
        self::assertSame('secret-key', $stored['octavawms_api_key'] ?? null);
        self::assertIsArray($settings);
        self::assertSame('https://pro.oawms.com/apps/woocommerce/api/label', $settings['label_endpoint']);
        self::assertSame('secret-key', $settings['api_key']);
        self::assertSame('123', $settings['source_id']);
    }
}
