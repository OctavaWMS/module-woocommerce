<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Admin;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\AdminLabelActions;
use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class LabelAjaxTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['octavawms_test_wc_get_order_callback']);
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
        parent::tearDown();
    }

    public function testPatchKindRetryPendingErrorMatchesOrderPanelScript(): void
    {
        self::assertSame('retry_pending_error', LabelAjax::PATCH_KIND_RETRY_PENDING_ERROR);
        self::assertSame('requeue_ending_queued', LabelAjax::PATCH_KIND_REQUEUE_ENDING_QUEUED);
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

    public function testHandleAjaxOrderStatusExposesBackendDownloadForClosedShipmentWithTask(): void
    {
        $_POST = ['order_id' => '42'];
        $_REQUEST = $_POST;
        $order = new \WC_Order(42, 'wc_order_testkey99');
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn (): \WC_Order => $order;

        Functions\when('__')->returnArg(1);
        Functions\when('absint')->alias(static fn ($v): int => abs((int) $v));
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('get_option')->justReturn('kg');
        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://admin.example/' . ltrim($path, '/'));
        Functions\when('wp_nonce_url')->alias(static fn (string $url, string $action): string => $url . '&_wpnonce=' . rawurlencode($action));

        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(static function (array $payload): void {
                self::assertTrue($payload['has_label_locally']);
                self::assertSame('63573931863', $payload['shipment']['tracking_number'] ?? null);
                self::assertStringContainsString('action=octavawms_download_label', (string) $payload['download_url']);
                self::assertStringContainsString('shipment_id=777', (string) $payload['download_url']);
                throw new \RuntimeException('wp_send_json_success');
            });

        $api = new class extends BackendApiClient {
            public function findOrderByExtId(string $extId): ?array
            {
                unset($extId);

                return ['id' => 1001, 'extId' => 'wc_order_testkey99'];
            }

            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                unset($backendOrder, $extIdCandidates);

                return [['id' => 777, 'state' => 'closed', 'trackingNumber' => '63573931863']];
            }

            public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
            {
                \PHPUnit\Framework\Assert::assertSame(777, $deliveryRequestId);

                return ['ok' => true, 'task_id' => 501, 'queue_id' => 99];
            }
        };
        $labelService = $this->getMockBuilder(LabelService::class)->disableOriginalConstructor()->getMock();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_success');

        $ajax->handleAjaxOrderStatus();
    }

    public function testHandleAjaxOrderStatusDoesNotExposeBackendDownloadWithoutTask(): void
    {
        $_POST = ['order_id' => '42'];
        $_REQUEST = $_POST;
        $order = new \WC_Order(42, 'wc_order_testkey99');
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn (): \WC_Order => $order;

        Functions\when('__')->returnArg(1);
        Functions\when('absint')->alias(static fn ($v): int => abs((int) $v));
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('get_option')->justReturn('kg');

        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(static function (array $payload): void {
                self::assertFalse($payload['has_label_locally']);
                self::assertSame('', $payload['download_url']);
                throw new \RuntimeException('wp_send_json_success');
            });

        $api = new class extends BackendApiClient {
            public function findOrderByExtId(string $extId): ?array
            {
                unset($extId);

                return ['id' => 1001, 'extId' => 'wc_order_testkey99'];
            }

            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                unset($backendOrder, $extIdCandidates);

                return [['id' => 777, 'state' => 'closed']];
            }

            public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
            {
                unset($deliveryRequestId);

                return ['ok' => true, 'task_id' => null, 'queue_id' => 99];
            }
        };
        $labelService = $this->getMockBuilder(LabelService::class)->disableOriginalConstructor()->getMock();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_success');

        $ajax->handleAjaxOrderStatus();
    }

    public function testResolveBackendLabelDownloadValidatesShipmentAndDownloadsTaskLabel(): void
    {
        $order = new \WC_Order(42, 'wc_order_testkey99');
        $api = new class extends BackendApiClient {
            public function findOrderByExtId(string $extId): ?array
            {
                unset($extId);

                return ['id' => 1001, 'extId' => 'wc_order_testkey99'];
            }

            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                unset($backendOrder, $extIdCandidates);

                return [['id' => 777, 'state' => 'closed']];
            }

            public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
            {
                \PHPUnit\Framework\Assert::assertSame(777, $deliveryRequestId);

                return ['ok' => true, 'task_id' => 501, 'queue_id' => 99];
            }

            public function downloadPreprocessingTaskLabel(int $taskId): array
            {
                \PHPUnit\Framework\Assert::assertSame(501, $taskId);

                return ['ok' => true, 'ready' => true, 'pdf' => '%PDF-test', 'content_type' => 'application/pdf', 'status' => 200];
            }
        };
        $labelService = $this->getMockBuilder(LabelService::class)->disableOriginalConstructor()->getMock();
        $labelMetaBox = new LabelMetaBox();
        $labelAjax = new LabelAjax($api, $labelService, $labelMetaBox);
        $actions = new AdminLabelActions($labelService, $labelMetaBox, $labelAjax, $api);

        $result = $actions->resolveBackendLabelDownload($order, 777);

        self::assertTrue($result['ok']);
        self::assertSame('%PDF-test', $result['body']);
        self::assertSame('application/pdf', $result['content_type']);
        self::assertSame('pdf', $result['extension']);
    }

    public function testResolveBackendLabelDownloadRejectsShipmentOutsideOrder(): void
    {
        $order = new \WC_Order(42, 'wc_order_testkey99');
        $api = new class extends BackendApiClient {
            public function findOrderByExtId(string $extId): ?array
            {
                unset($extId);

                return ['id' => 1001, 'extId' => 'wc_order_testkey99'];
            }

            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                unset($backendOrder, $extIdCandidates);

                return [['id' => 123, 'state' => 'closed']];
            }
        };
        $labelService = $this->getMockBuilder(LabelService::class)->disableOriginalConstructor()->getMock();
        $labelMetaBox = new LabelMetaBox();
        $labelAjax = new LabelAjax($api, $labelService, $labelMetaBox);
        $actions = new AdminLabelActions($labelService, $labelMetaBox, $labelAjax, $api);

        $result = $actions->resolveBackendLabelDownload($order, 777);

        self::assertFalse($result['ok']);
        self::assertSame('', $result['body']);
    }

    public function testHandleAjaxCancelLabelCancelsExistingTaskAndClearsLocalLabel(): void
    {
        $_POST = ['order_id' => '42', 'shipment_id' => '777'];
        $_REQUEST = $_POST;
        $order = new \WC_Order(42, 'wc_order_testkey99');
        $order->update_meta_data(LabelService::ORDER_META_LABEL_URL, 'https://labels.example/old.pdf');
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn (): \WC_Order => $order;

        Functions\when('__')->returnArg(1);
        Functions\when('absint')->alias(static fn ($v): int => abs((int) $v));
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);

        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(static function (array $payload) use ($order): void {
                self::assertSame(['cancelled' => true], $payload);
                self::assertSame('', $order->get_meta(LabelService::ORDER_META_LABEL_URL, true));
                self::assertSame(1, $order->saveCallCount);
                throw new \RuntimeException('wp_send_json_success');
            });

        $api = new class extends BackendApiClient {
            public function findOrderByExtId(string $extId): ?array
            {
                unset($extId);

                return ['id' => 1001, 'extId' => 'wc_order_testkey99'];
            }

            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                unset($backendOrder, $extIdCandidates);

                return [['id' => 777, 'state' => 'closed']];
            }

            public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
            {
                \PHPUnit\Framework\Assert::assertSame(777, $deliveryRequestId);

                return ['ok' => true, 'task_id' => 501, 'queue_id' => 99];
            }

            public function createOrUpdatePreprocessingTask(?int $taskId, array $payload, bool $retried = false): array
            {
                unset($retried);
                \PHPUnit\Framework\Assert::assertSame(501, $taskId);
                \PHPUnit\Framework\Assert::assertSame(['state' => 'cancel'], $payload);

                return ['ok' => true, 'pdf' => null, 'content_type' => '', 'task_id' => $taskId];
            }
        };
        $labelService = $this->getMockBuilder(LabelService::class)->disableOriginalConstructor()->getMock();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_success');

        $ajax->handleAjaxCancelLabel();
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
