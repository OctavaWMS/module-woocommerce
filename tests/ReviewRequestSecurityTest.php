<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\Admin\SettingsAjax;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use OctavaWMS\WooCommerce\Checkout\CheckoutDeliveryService;
use OctavaWMS\WooCommerce\ConnectService;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReviewRequestSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = ['order_id' => '42', 'shipment_id' => '7', 'place_id' => '8', 'subaction' => 'save'];
        Functions\when('__')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('absint')->alias(static fn ($value): int => abs((int) $value));
        Functions\expect('wp_remote_request')->never();
        Functions\expect('wp_remote_post')->never();
        Functions\expect('update_option')->never();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public static function adminHandlers(): iterable
    {
        yield 'connect' => [ConnectService::class, 'handleAjaxConnect', ConnectService::ACTION, 'security', 'manage_woocommerce'];
        yield 'panel login' => [ConnectService::class, 'handleAjaxPanelLoginUrl', ConnectService::PANEL_LOGIN_NONCE_ACTION, 'security', 'manage_woocommerce'];
        yield 'settings' => [SettingsAjax::class, 'handleAjax', SettingsAjax::ACTION, 'security', 'manage_woocommerce'];
        foreach ([
            'OrderStatus' => 'octavawms_order_status_42',
            'UploadOrder' => 'octavawms_upload_order_42',
            'ImportStatus' => 'octavawms_import_status_42',
            'GenerateLabel' => 'octavawms_generate_label_42',
            'CancelLabel' => 'octavawms_cancel_label_42',
            'ShipmentDetail' => 'octavawms_connector_42',
            'ServicePoints' => 'octavawms_connector_42',
            'SaveServicePoint' => 'octavawms_connector_42',
            'PatchShipment' => 'octavawms_connector_42',
            'DeliveryServices' => 'octavawms_connector_42',
            'Localities' => 'octavawms_connector_42',
            'Places' => 'octavawms_connector_42',
            'AddPlace' => 'octavawms_connector_42',
            'UpdatePlace' => 'octavawms_connector_42',
            'DeletePlace' => 'octavawms_connector_42',
        ] as $suffix => $nonce) {
            yield $suffix => [LabelAjax::class, 'handleAjax' . $suffix, $nonce, 'nonce', 'edit_shop_orders'];
        }
    }

    #[DataProvider('adminHandlers')]
    public function testUnauthorizedUserCannotReadOrWriteBackend(string $class, string $method, string $nonce, string $field, string $capability): void
    {
        Functions\expect('current_user_can')->once()->withArgs(static fn ($actual): bool => $actual === $capability)->andReturn(false);
        Functions\expect('check_ajax_referer')->never();
        Functions\expect('wp_send_json_error')->once()->with(\Mockery::type('array'), 403)->andThrow(new \RuntimeException('permission denied'));
        $this->expectExceptionMessage('permission denied');
        $this->handler($class)->$method();
    }

    #[DataProvider('adminHandlers')]
    public function testInvalidNonceStopsBeforeBackendAccess(string $class, string $method, string $nonce, string $field, string $capability): void
    {
        Functions\expect('current_user_can')->once()->withArgs(static fn ($actual): bool => $actual === $capability)->andReturn(true);
        Functions\expect('check_ajax_referer')->once()->with($nonce, $field)->andThrow(new \RuntimeException('invalid nonce'));
        $this->expectExceptionMessage('invalid nonce');
        $this->handler($class)->$method();
    }

    public function testGuestPointLookupRejectsInvalidNonceBeforeBackendAccess(): void
    {
        Functions\expect('check_ajax_referer')->once()->with(CheckoutDeliveryService::NONCE_ACTION, 'nonce')->andThrow(new \RuntimeException('invalid nonce'));
        $this->expectExceptionMessage('invalid nonce');
        (new CheckoutDeliveryService(new BackendApiClient()))->handleServicePoints();
    }

    private function handler(string $class): object
    {
        $api = new BackendApiClient();
        return match ($class) {
            ConnectService::class => new ConnectService(),
            SettingsAjax::class => new SettingsAjax($api),
            LabelAjax::class => new LabelAjax($api, new LabelService($api), new LabelMetaBox()),
        };
    }
}
