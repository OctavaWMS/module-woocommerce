<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\AdminLabelActions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use WC_Order;

final class AdminLabelActionsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transients = [];
        unset($GLOBALS['octavawms_test_wc_get_order_callback']);

        Functions\when('__')->alias(static fn (string $text, $domain = null): string => $text);
        Functions\when('current_user_can')->alias(static fn (string $capability, mixed ...$args): bool => true);
        Functions\when('get_current_user_id')->justReturn(11);
        Functions\when('set_transient')->alias(function (string $key, mixed $value, int $ttl = 0): bool {
            unset($ttl);
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_option')->alias(static function (string $name, mixed $default = false): mixed {
            if ($name === 'woocommerce_weight_unit') {
                return 'kg';
            }

            return $default;
        });
        Functions\when('wp_strip_all_tags')->alias(static fn (string $text): string => strip_tags($text));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['octavawms_test_wc_get_order_callback']);
        parent::tearDown();
    }

    public function testRegisterAddsNativeBulkActionHooks(): void
    {
        $filters = [];
        $actions = [];
        Functions\when('add_filter')->alias(static function (string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1) use (&$filters): void {
            unset($callback);
            $filters[] = [$hook, $priority, $acceptedArgs];
        });
        Functions\when('add_action')->alias(static function (string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1) use (&$actions): void {
            unset($callback);
            $actions[] = [$hook, $priority, $acceptedArgs];
        });

        $metaBox = $this->createMock(LabelMetaBox::class);
        $metaBox->expects(self::once())->method('register');
        $ajax = $this->getMockBuilder(LabelAjax::class)->disableOriginalConstructor()->getMock();
        $ajax->expects(self::once())->method('register');

        $this->actions($this->createMock(LabelService::class), $metaBox, $ajax, $this->createMock(BackendApiClient::class))->register();

        self::assertContains(['bulk_actions-edit-shop_order', 10, 1], $filters);
        self::assertContains(['bulk_actions-woocommerce_page_wc-orders', 10, 1], $filters);
        self::assertContains(['handle_bulk_actions-edit-shop_order', 10, 3], $filters);
        self::assertContains(['handle_bulk_actions-woocommerce_page_wc-orders', 10, 3], $filters);
        self::assertContains(['admin_notices', 10, 1], $actions);
    }

    public function testBulkActionLabelsAreAppPrefixedAndTranslatable(): void
    {
        $bulkActions = $this->actions($this->createMock(LabelService::class))->addBulkLabelActions([]);

        self::assertSame('OctavaWMS: Create labels', $bulkActions['octavawms_bulk_create_labels'] ?? null);
        self::assertSame('OctavaWMS: Print labels', $bulkActions['octavawms_bulk_print_labels'] ?? null);
        self::assertSame(
            'OctavaWMS: Create and print labels',
            $bulkActions['octavawms_bulk_create_print_labels'] ?? null
        );
    }

    public function testBulkCreateSkipsExistingCreatesMissingAndReportsFailures(): void
    {
        $existing = new WC_Order(1, 'wc_order_one', '', '50001');
        $existing->update_meta_data(LabelService::ORDER_META_LABEL_FILE, '/tmp/existing.pdf');
        $create = new WC_Order(2, 'wc_order_two', '', '50002');
        $fail = new WC_Order(3, 'wc_order_three', '', '50003');
        $orders = [1 => $existing, 2 => $create, 3 => $fail];
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn ($orderId) => $orders[(int) $orderId] ?? false;

        $api = $this->createMock(BackendApiClient::class);
        $api->method('findOrderByExtId')->willReturn(['id' => 100]);

        $labelService = $this->createMock(LabelService::class);
        $labelService->expects(self::exactly(2))
            ->method('requestLabel')
            ->willReturnOnConsecutiveCalls(
                ['status' => 'success', 'label_file' => '/tmp/new-label.pdf'],
                ['status' => 'error', 'message' => 'No shipment found']
            );

        $metaBox = $this->createMock(LabelMetaBox::class);
        $metaBox->method('buildDownloadMarkup')->willReturn('<a href="/download">Download</a>');

        $this->actions($labelService, $metaBox, null, $api)->handleBulkLabelAction(
            '/wp-admin/edit.php?post_type=shop_order',
            'octavawms_bulk_create_labels',
            [1, 2, 3]
        );

        $notice = $this->storedNotice();
        self::assertSame(1, $notice['counts']['skipped'] ?? 0);
        self::assertSame(1, $notice['counts']['created'] ?? 0);
        self::assertSame(1, $notice['counts']['failed'] ?? 0);
        self::assertSame('/tmp/new-label.pdf', $create->get_meta(LabelService::ORDER_META_LABEL_FILE, true));
        self::assertSame('', $create->get_meta(LabelService::ORDER_META_LABEL_URL, true));
        self::assertNotEmpty($create->orderNotes);
        self::assertNotEmpty($fail->orderNotes);
    }

    public function testBulkPrintImportsPrintableOrdersAndReportsUnprintableOrders(): void
    {
        $orders = [
            1 => new WC_Order(1, 'wc_order_one', '', '50560'),
            2 => new WC_Order(2, 'wc_order_two', '', '50561'),
            3 => new WC_Order(3, 'wc_order_three', '', '50562'),
        ];
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn ($orderId) => $orders[(int) $orderId] ?? false;

        $api = $this->createMock(BackendApiClient::class);
        $api->method('findOrderByExtId')->willReturn(['id' => 100]);
        $api->method('findShipmentsForConnector')->willReturnCallback(static function (?array $backendOrder, array $candidates): array {
            unset($backendOrder);

            return [['id' => ((int) $candidates[0]) + 1000]];
        });
        $api->method('findPreprocessingTasksForShipment')->willReturnCallback(static function (int $shipmentId): array {
            return $shipmentId === 51561
                ? ['ok' => true, 'task_id' => null, 'queue_id' => null]
                : ['ok' => true, 'task_id' => $shipmentId + 10, 'queue_id' => 7];
        });
        $api->method('getShipmentById')->willReturn(['_embedded' => ['sender' => ['id' => 77]]]);
        $api->expects(self::once())
            ->method('importBulkLabels')
            ->with([51560, 51562], 77, true)
            ->willReturn(['ok' => true, 'import_id' => 88, 'file_url' => 'https://files.example/labels.pdf', 'state' => 'confirmed']);

        $this->actions($this->createMock(LabelService::class), null, null, $api)->handleBulkLabelAction(
            '/wp-admin/edit.php?post_type=shop_order',
            'octavawms_bulk_print_labels',
            [1, 2, 3]
        );

        $notice = $this->storedNotice();
        self::assertSame(2, $notice['counts']['printed'] ?? 0);
        self::assertSame(1, $notice['counts']['not_printable'] ?? 0);
        self::assertSame('https://files.example/labels.pdf', $notice['download_url'] ?? null);
    }

    public function testBulkCreateAndPrintCreatesMissingThenPrintsAllPrintableOrders(): void
    {
        $already = new WC_Order(1, 'wc_order_one', '', '70001');
        $already->update_meta_data(LabelService::ORDER_META_LABEL_FILE, '/tmp/existing.pdf');
        $missing = new WC_Order(2, 'wc_order_two', '', '70002');
        $orders = [1 => $already, 2 => $missing];
        $GLOBALS['octavawms_test_wc_get_order_callback'] = static fn ($orderId) => $orders[(int) $orderId] ?? false;

        $api = $this->createMock(BackendApiClient::class);
        $api->method('findOrderByExtId')->willReturn(['id' => 100]);
        $api->method('findShipmentsForConnector')->willReturnCallback(static function (?array $backendOrder, array $candidates): array {
            unset($backendOrder);

            return [['id' => ((int) $candidates[0]) + 1000]];
        });
        $api->method('findPreprocessingTasksForShipment')->willReturn(['ok' => true, 'task_id' => 123, 'queue_id' => 7]);
        $api->method('getShipmentById')->willReturn(['sender' => ['id' => 77]]);
        $api->expects(self::once())
            ->method('importBulkLabels')
            ->with([71001, 71002], 77, true)
            ->willReturn(['ok' => true, 'import_id' => 91, 'file_url' => 'https://files.example/combined.pdf', 'state' => 'confirmed']);

        $labelService = $this->createMock(LabelService::class);
        $labelService->expects(self::once())
            ->method('requestLabel')
            ->willReturn(['status' => 'success', 'label_file' => '/tmp/created.pdf']);

        $metaBox = $this->createMock(LabelMetaBox::class);
        $metaBox->method('buildDownloadMarkup')->willReturn('<a href="/download">Download</a>');

        $this->actions($labelService, $metaBox, null, $api)->handleBulkLabelAction(
            '/wp-admin/edit.php?post_type=shop_order',
            'octavawms_bulk_create_print_labels',
            [1, 2]
        );

        $notice = $this->storedNotice();
        self::assertSame(1, $notice['counts']['skipped'] ?? 0);
        self::assertSame(1, $notice['counts']['created'] ?? 0);
        self::assertSame(2, $notice['counts']['printed'] ?? 0);
        self::assertSame('https://files.example/combined.pdf', $notice['download_url'] ?? null);
        self::assertSame('/tmp/created.pdf', $missing->get_meta(LabelService::ORDER_META_LABEL_FILE, true));
    }

    private function actions(
        LabelService $labelService,
        ?LabelMetaBox $metaBox = null,
        ?LabelAjax $ajax = null,
        ?BackendApiClient $api = null
    ): AdminLabelActions {
        return new AdminLabelActions(
            $labelService,
            $metaBox ?? $this->createMock(LabelMetaBox::class),
            $ajax ?? $this->getMockBuilder(LabelAjax::class)->disableOriginalConstructor()->getMock(),
            $api ?? $this->createMock(BackendApiClient::class)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedNotice(): array
    {
        self::assertArrayHasKey('octavawms_bulk_label_notice_11', $this->transients);
        $notice = $this->transients['octavawms_bulk_label_notice_11'];
        self::assertIsArray($notice);

        return $notice;
    }
}
