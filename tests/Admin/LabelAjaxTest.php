<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Admin;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class LabelAjaxTest extends TestCase
{
    public function testPatchKindRetryPendingErrorMatchesOrderPanelScript(): void
    {
        self::assertSame('retry_pending_error', LabelAjax::PATCH_KIND_RETRY_PENDING_ERROR);
    }

    public function testHandleAjaxOrderStatusSendsErrorWhenOrderIdMissing(): void
    {
        $_POST = [];
        $_REQUEST = [];

        Functions\when('__')->returnArg(1);

        Functions\expect('wp_send_json_error')
            ->once()
            ->andReturnUsing(static function (): void {
                throw new \RuntimeException('wp_send_json_error');
            });

        $labelService = $this->getMockBuilder(LabelService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $api = new BackendApiClient();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_error');

        $ajax->handleAjaxOrderStatus();
    }

    public function testBuildShipmentDetailPayloadMapsDeliveryServiceStatusForPendingError(): void
    {
        $api = new BackendApiClient();
        $labelService = $this->getMockBuilder(LabelService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $m = new \ReflectionMethod(LabelAjax::class, 'buildShipmentDetailPayload');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke($ajax, [
            'state' => 'pending_error',
            'errors' => null,
            'deliveryServiceStatus' => 'Sender profile should be set',
            'eav' => [],
            '_embedded' => [],
        ]);

        self::assertSame('pending_error', $out['shipment_state']);
        self::assertSame('Sender profile should be set', $out['shipment_error_message']);
    }

    public function testBuildShipmentDetailPayloadOmitsErrorWhenNotPendingError(): void
    {
        $api = new BackendApiClient();
        $labelService = $this->getMockBuilder(LabelService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $m = new \ReflectionMethod(LabelAjax::class, 'buildShipmentDetailPayload');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke($ajax, [
            'state' => 'measured',
            'deliveryServiceStatus' => 'Would be ignored for non-error state in UI',
            'eav' => [],
            '_embedded' => [],
        ]);

        self::assertSame('', $out['shipment_error_message'] ?? '');
    }

    public function testBuildShipmentDetailPayloadIncludesServicePointContextFromEav(): void
    {
        $api = new BackendApiClient();
        $labelService = $this->getMockBuilder(LabelService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $m = new \ReflectionMethod(LabelAjax::class, 'buildShipmentDetailPayload');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke($ajax, [
            'state' => 'measured',
            'eav' => [
                'delivery-request-service-point' => 'Near office A',
                'delivery-request-service-point-distance' => 12.345,
            ],
            '_embedded' => [],
        ]);

        self::assertSame('Near office A', $out['service_point_context']['ai_message']);
        self::assertSame(12.35, $out['service_point_context']['distance_m']);
    }

    public function testSimplifyServicePointRowParsesExtendedApiShape(): void
    {
        $api = new BackendApiClient();
        $labelService = $this->getMockBuilder(LabelService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $m = new \ReflectionMethod(LabelAjax::class, 'simplifyServicePointRow');
        $m->setAccessible(true);
        $rawDesc = json_encode([
            [
                'standardSchedule' => true,
                'workingTimeFrom' => '09:00',
                'workingTimeTo' => '18:00',
            ],
        ], JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $out */
        $out = $m->invoke($ajax, [
            'id' => 9,
            'name' => 'Office 1',
            'extId' => 'EXT-9',
            'rawAddress' => 'Main St 1',
            'rawPhone' => '+359 88',
            'type' => 'service_point',
            'state' => 'active',
            'geo' => 'POINT (23.3 42.7)',
            'rawDescription' => $rawDesc,
        ]);

        self::assertSame(9, $out['id']);
        self::assertSame('+359 88', $out['raw_phone']);
        self::assertStringContainsString('09:00', (string) ($out['working_hours_summary'] ?? ''));
        self::assertStringContainsString('18:00', (string) ($out['working_hours_summary'] ?? ''));
        self::assertSame('Main St 1', $out['address']);
    }
}
