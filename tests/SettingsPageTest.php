<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\SettingsPage;

final class SettingsPageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    /** @var list<string> */
    private array $hooksAdded = [];

    /** @var list<string> */
    private array $settingsErrors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stored = [];
        $this->hooksAdded = [];
        $this->settingsErrors = [];
        $_POST = [];

        Functions\when('__')->alias(static fn (string $text, $domain = null): string => $text);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('add_action')->alias(function (string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void {
            unset($callback, $priority, $acceptedArgs);
            $this->hooksAdded[] = $hook;
        });
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return $this->stored[$name] ?? $default;
        });
        Functions\when('update_option')->alias(function (string $name, $value): void {
            $this->stored[$name] = $value;
        });
        Functions\when('add_settings_error')->alias(function (string $setting, string $code, string $message, string $type = 'error'): void {
            unset($setting, $code, $type);
            $this->settingsErrors[] = $message;
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function testConstructorRegistersWooCommerceIntegrationSaveHook(): void
    {
        new SettingsPage();

        self::assertContains(
            'woocommerce_update_options_integration_' . Options::INTEGRATION_ID,
            $this->hooksAdded
        );
    }

    public function testProcessAdminOptionsPreservesConnectionSettings(): void
    {
        $this->stored['woocommerce_octavawms_settings'] = [
            'api_base' => '',
            'api_key' => 'old-token',
            'source_id' => '77',
            'label_endpoint' => 'https://pro.oawms.com/apps/woocommerce/api/label',
            'refresh_token' => 'rt',
            'oauth_domain' => 'tenant',
            'sync_new_orders' => 'yes',
            'sync_order_updates' => 'yes',
            'import_async' => 'yes',
        ];

        $page = new SettingsPage();

        $_POST = [
            'woocommerce_octavawms_api_base' => 'https://custom.example.test',
            'woocommerce_octavawms_api_key' => 'new-token',
            'woocommerce_octavawms_sync_new_orders' => '1',
            'woocommerce_octavawms_sync_order_updates' => '1',
            'woocommerce_octavawms_import_async' => '1',
        ];

        $page->process_admin_options();

        $settings = $this->stored['woocommerce_octavawms_settings'] ?? [];
        self::assertIsArray($settings);
        self::assertSame('https://custom.example.test', $settings['api_base'] ?? null);
        self::assertSame('new-token', $settings['api_key'] ?? null);
        self::assertSame('77', $settings['source_id'] ?? null);
        self::assertSame('https://pro.oawms.com/apps/woocommerce/api/label', $settings['label_endpoint'] ?? null);
        self::assertSame('rt', $settings['refresh_token'] ?? null);
        self::assertSame('tenant', $settings['oauth_domain'] ?? null);
        self::assertSame('new-token', $this->stored[Options::LEGACY_API_KEY] ?? null);
    }

    public function testProcessAdminOptionsStoresCarrierMappingJsonLocally(): void
    {
        $this->stored['woocommerce_octavawms_settings'] = [
            'api_key' => 'token',
            'source_id' => '0',
            'sync_new_orders' => 'yes',
            'sync_order_updates' => 'yes',
            'import_async' => 'yes',
        ];

        $page = new SettingsPage();

        $_POST = [
            'woocommerce_octavawms_api_key' => 'token',
            'woocommerce_octavawms_sync_new_orders' => '1',
            'woocommerce_octavawms_sync_order_updates' => '1',
            'woocommerce_octavawms_import_async' => '1',
            'woocommerce_octavawms_carrier_mapping_json' => json_encode([
                [
                    'courierMetaKey' => 'courierName',
                    'courierMetaValue' => 'Speedy',
                    'wooDeliveryType' => 'office',
                    'type' => 'office',
                    'deliveryService' => 23,
                    'rate' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ];

        $page->process_admin_options();

        $settings = $this->stored['woocommerce_octavawms_settings'] ?? [];
        self::assertIsArray($settings);
        self::assertIsString($settings[Options::CARRIER_MAPPING_JSON] ?? null);

        $decoded = json_decode((string) $settings[Options::CARRIER_MAPPING_JSON], true);
        self::assertSame('courierName', $decoded[0]['courierMetaKey'] ?? null);
        self::assertSame(23, $decoded[0]['deliveryService'] ?? null);
    }

    public function testProcessAdminOptionsKeepsLocalCarrierMappingWhenRemotePatchFails(): void
    {
        $this->stored['woocommerce_octavawms_settings'] = [
            'api_key' => 'token',
            'source_id' => '77',
            'sync_new_orders' => 'yes',
            'sync_order_updates' => 'yes',
            'import_async' => 'yes',
        ];

        $payload = [
            [
                'courierMetaKey' => 'courierName',
                'courierMetaValue' => 'Speedy',
                'wooDeliveryType' => 'office',
                'type' => 'office',
                'deliveryService' => 23,
                'rate' => null,
            ],
            [
                'courierMetaKey' => 'courierID',
                'courierMetaValue' => '18',
                'wooDeliveryType' => 'office',
                'type' => 'office',
                'deliveryService' => 23,
                'rate' => null,
            ],
        ];

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::once())
            ->method('getIntegrationSource')
            ->with(77)
            ->willReturn(['settings' => ['DeliveryServices' => ['options' => []]]]);
        $api->expects(self::once())
            ->method('patchIntegrationSource')
            ->willReturn([
                'ok' => false,
                'status' => 422,
                'data' => ['detail' => 'backend rejected mapping'],
                'raw' => '',
                'response_headers' => [],
            ]);

        $page = new class ($api) extends SettingsPage {
            public function __construct(private BackendApiClient $testApi)
            {
                parent::__construct();
            }

            protected function createBackendApiClient(): BackendApiClient
            {
                return $this->testApi;
            }
        };

        $_POST = [
            'woocommerce_octavawms_api_key' => 'token',
            'woocommerce_octavawms_sync_new_orders' => '1',
            'woocommerce_octavawms_sync_order_updates' => '1',
            'woocommerce_octavawms_import_async' => '1',
            'woocommerce_octavawms_carrier_mapping_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];

        $page->process_admin_options();

        $settings = $this->stored['woocommerce_octavawms_settings'] ?? [];
        self::assertIsArray($settings);
        self::assertIsString($settings[Options::CARRIER_MAPPING_JSON] ?? null);
        self::assertSame($payload, json_decode((string) $settings[Options::CARRIER_MAPPING_JSON], true));
        self::assertCount(1, $this->settingsErrors);
        self::assertStringContainsString('backend rejected mapping', $this->settingsErrors[0]);
    }
}
