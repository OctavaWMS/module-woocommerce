<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\OrderSyncService;

final class OrderSyncServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var list<string> */
    private array $hooksAdded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transients = [];
        $this->hooksAdded = [];
        unset($GLOBALS['octavawms_test_wc_get_order_callback']);

        Functions\when('add_action')->alias(function (string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
            unset($callback, $priority, $accepted_args);
            $this->hooksAdded[] = $hook;
        });

        Functions\when('apply_filters')->alias(static function (string $hook, $value, mixed ...$args) {
            unset($hook, $args);

            return $value;
        });

        Functions\when('get_transient')->alias(function (string $key) {
            return $this->transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function (string $key, $value, int $ttl = 0): void {
            unset($ttl);
            $this->transients[$key] = $value;
        });
        Functions\when('wc_get_logger')->alias(static function () {
            return new class () {
                public function log(string $_level = '', string $_message = '', array $_ctx = []): void {}
            };
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['octavawms_test_wc_get_order_callback']);
        parent::tearDown();
    }

    /**
     * @return array{service: OrderSyncService, order: WC_Order}
     */
    private function serviceWithConfiguredOrder(int $orderId, BackendApiClient $api): array
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'api_key' => 'bearer-token',
                    'source_id' => '7',
                    'sync_new_orders' => 'yes',
                    'sync_order_updates' => 'yes',
                ];
            }

            return $default;
        });

        $order = new \WC_Order($orderId, 'wc_order_extfilter_1', '');
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static function ($id) use ($orderId, $order) {
            return (int) $id === $orderId ? $order : false;
        };

        return ['service' => new OrderSyncService($api), 'order' => $order];
    }

    public function testRegisterAddsWooCommerceActions(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        (new OrderSyncService($api))->register();

        self::assertSame(
            [
                'woocommerce_checkout_order_processed',
                'woocommerce_new_order',
                'woocommerce_update_order',
                'woocommerce_order_status_changed',
            ],
            $this->hooksAdded
        );
    }

    public function testRegisterSkipsActionsWhenFilteredOff(): void
    {
        Functions\when('apply_filters')->alias(static function (string $hook, $value, mixed ...$args) {
            unset($args);
            if ($hook === 'octavawms_register_order_sync_hooks') {
                return false;
            }

            return $value;
        });

        $api = $this->createMock(BackendApiClient::class);
        (new OrderSyncService($api))->register();

        self::assertSame([], $this->hooksAdded);
    }

    public function testOnNewOrderSkipsWhenSyncDisabled(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['sync_new_orders' => 'no'];
            }

            return $default;
        });

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::never())->method('importOrder');

        (new OrderSyncService($api))->onNewOrder(1);
    }

    public function testOnNewOrderSkipsWhenNotConnected(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'sync_new_orders' => 'yes',
                    'api_key' => '',
                    'source_id' => '0',
                ];
            }

            return $default;
        });

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::never())->method('importOrder');

        (new OrderSyncService($api))->onNewOrder(5);
    }

    public function testOnNewOrderCallsImportOncePerRequest(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::once())
            ->method('importOrder')
            ->with('100', 7)
            ->willReturn(['ok' => true, 'data' => null]);
        $api->expects(self::never())->method('extractFirstOrderFromCollectionJson');

        ['service' => $service] = $this->serviceWithConfiguredOrder(100, $api);
        $service->onNewOrder(100);
        $service->onCheckoutOrderProcessed(100, []);
    }

    public function testOnNewOrderPersistsCanonicalExtIdFromResponse(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        $api->method('importOrder')->willReturn([
            'ok' => true,
            'data' => ['_embedded' => ['orders' => [['extId' => 'canonical-from-api']]]],
        ]);
        $api->method('extractFirstOrderFromCollectionJson')->willReturn(['extId' => 'canonical-from-api']);

        ['service' => $service, 'order' => $order] = $this->serviceWithConfiguredOrder(200, $api);
        $service->onNewOrder(200);

        self::assertSame(['_octavawms_external_order_id' => 'canonical-from-api'], $order->updatedMeta);
        self::assertSame(1, $order->saveCallCount);
    }

    public function testOnOrderUpdateUsesTransientToDebounce(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'api_key' => 't',
                    'source_id' => '3',
                    'sync_order_updates' => 'yes',
                ];
            }

            return $default;
        });

        $order = new \WC_Order(55, 'wc_key55', '');
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn (int $id) => $id === 55 ? $order : false;

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::once())
            ->method('importOrder')
            ->willReturn(['ok' => true, 'data' => null]);

        $service = new OrderSyncService($api);
        $service->onOrderUpdate(55);
        $service->onOrderStatusChanged(55, 'pending', 'processing');
    }

    public function testOnOrderUpdateSkipsWhenSettingDisabled(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'api_key' => 't',
                    'source_id' => '3',
                    'sync_order_updates' => 'no',
                ];
            }

            return $default;
        });

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::never())->method('importOrder');

        (new OrderSyncService($api))->onOrderUpdate(1);
    }

    public function testResolveOrderIdFromOrderObject(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['api_key' => 't', 'source_id' => '1', 'sync_new_orders' => 'yes'];
            }

            return $default;
        });

        $order = new \WC_Order(88, 'wc_88', '');
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn (int $id) => $id === 88 ? $order : false;

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::once())->method('importOrder')->with('88', 1)->willReturn(['ok' => true, 'data' => null]);

        (new OrderSyncService($api))->onNewOrder($order);
    }

    public function testOnNewOrderSkipsWhenCrossRequestThrottleActive(): void
    {
        $this->transients['octavawms_ord_import_100'] = '1';

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::never())->method('importOrder');

        ['service' => $service] = $this->serviceWithConfiguredOrder(100, $api);
        $service->onNewOrder(100);
    }

    /** Second {@see OrderSyncService} simulates another PHP request where in-request dedupe resets. */
    public function testThrottleAppliesAcrossNewServiceInstances(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::once())
            ->method('importOrder')
            ->with('100', 7)
            ->willReturn(['ok' => true, 'data' => null]);

        ['service' => $first] = $this->serviceWithConfiguredOrder(100, $api);
        $first->onNewOrder(100);
        self::assertArrayHasKey('octavawms_ord_import_100', $this->transients);

        $sameScenarioApi = $this->createMock(BackendApiClient::class);
        $sameScenarioApi->expects(self::never())->method('importOrder');

        ['service' => $second] = $this->serviceWithConfiguredOrder(100, $sameScenarioApi);
        $second->onNewOrder(100);
    }
}
