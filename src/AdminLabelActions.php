<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use OctavaWMS\WooCommerce\WooOrderExtId;
use OctavaWMS\WooCommerce\WooOrderWeights;
use WC_Order;

class AdminLabelActions
{
    private const BULK_CREATE_ACTION = 'octavawms_bulk_create_labels';
    private const BULK_PRINT_ACTION = 'octavawms_bulk_print_labels';
    private const BULK_CREATE_PRINT_ACTION = 'octavawms_bulk_create_print_labels';
    private const BULK_NOTICE_TRANSIENT_PREFIX = 'octavawms_bulk_label_notice_';
    private const BULK_IMPORT_POLL_ATTEMPTS = 5;
    private const BULK_IMPORT_POLL_SLEEP_SECONDS = 1;

    private LabelService $labelService;

    private LabelMetaBox $labelMetaBox;

    private LabelAjax $labelAjax;

    private BackendApiClient $apiClient;

    public function __construct(LabelService $labelService, LabelMetaBox $labelMetaBox, LabelAjax $labelAjax, BackendApiClient $apiClient)
    {
        $this->labelService = $labelService;
        $this->labelMetaBox = $labelMetaBox;
        $this->labelAjax = $labelAjax;
        $this->apiClient = $apiClient;
    }

    public function register(): void
    {
        add_filter('woocommerce_admin_order_actions', [$this, 'addGenerateLabelOrderAction'], 20, 2);
        add_filter('woocommerce_order_actions', [$this, 'addOrderEditScreenAction'], 20, 2);
        add_action('woocommerce_order_action_octavawms_generate_label', [$this, 'handleGenerateLabelFromOrderMetabox']);

        add_action('admin_action_octavawms_generate_label', [$this, 'handleGenerateLabelAction']);
        add_action('admin_post_octavawms_download_label', [$this, 'handleDownloadLabelAction']);
        add_filter('bulk_actions-edit-shop_order', [$this, 'addBulkLabelActions']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addBulkLabelActions']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handleBulkLabelAction'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleBulkLabelAction'], 10, 3);
        add_action('admin_notices', [$this, 'renderBulkNotice']);

        $this->labelMetaBox->register();
        $this->labelAjax->register();
    }

    /**
     * @param array<string, mixed> $actions
     *
     * @return array<string, mixed>
     */
    public function addGenerateLabelOrderAction(array $actions, WC_Order $order): array
    {
        if (! current_user_can('edit_shop_orders')) {
            return $actions;
        }

        $orderId = (int) $order->get_id();
        $hasLabel = (bool) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true) || (bool) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);

        $actions['octavawms_generate_label'] = [
            'url' => wp_nonce_url(
                admin_url('admin.php?action=octavawms_generate_label&order_id=' . $orderId),
                'octavawms_generate_label_' . $orderId
            ),
            'name' => $hasLabel ? __('Re-generate Label', 'octavawms') : __('Generate Label', 'octavawms'),
            'action' => 'view octavawms-generate-label',
        ];

        return $actions;
    }

    /**
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function addOrderEditScreenAction(array $actions, ?WC_Order $order): array
    {
        if (! $order instanceof WC_Order || ! current_user_can('edit_shop_orders', $order->get_id())) {
            return $actions;
        }

        $hasLabel = (bool) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true)
            || (bool) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);

        $actions['octavawms_generate_label'] = $hasLabel
            ? __('Re-generate shipping label', 'octavawms')
            : __('Generate shipping label', 'octavawms');

        return $actions;
    }

    public function handleGenerateLabelFromOrderMetabox(WC_Order $order): void
    {
        if (! current_user_can('edit_shop_orders', $order->get_id())) {
            return;
        }

        $success = $this->executeLabelGeneration($order);

        if (! $success && class_exists(\WC_Admin_Meta_Boxes::class, false)) {
            \WC_Admin_Meta_Boxes::add_error(
                __('OctavaWMS could not generate a shipping label. See order notes.', 'octavawms')
            );
        }
    }

    public function handleGenerateLabelAction(): void
    {
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;

        if (! $orderId || ! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You are not allowed to generate labels.', 'octavawms'));
        }

        check_admin_referer('octavawms_generate_label_' . $orderId);

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'octavawms'));
        }

        $success = $this->executeLabelGeneration($order);
        wp_safe_redirect($this->orderEditUrl($orderId, $success ? 'success' : 'error'));
        exit;
    }

    /**
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function addBulkLabelActions(array $actions): array
    {
        if (! current_user_can('edit_shop_orders')) {
            return $actions;
        }

        $actions[self::BULK_CREATE_ACTION] = $this->appPrefixedBulkActionLabel(__('Create labels', 'octavawms'));
        $actions[self::BULK_PRINT_ACTION] = $this->appPrefixedBulkActionLabel(__('Print labels', 'octavawms'));
        $actions[self::BULK_CREATE_PRINT_ACTION] = $this->appPrefixedBulkActionLabel(__('Create and print labels', 'octavawms'));

        return $actions;
    }

    private function appPrefixedBulkActionLabel(string $actionLabel): string
    {
        return UiBranding::appActionLabel($actionLabel);
    }

    /**
     * @param array<int|string> $orderIds
     */
    public function handleBulkLabelAction(string $redirectTo, string $action, array $orderIds): string
    {
        if (! in_array($action, [self::BULK_CREATE_ACTION, self::BULK_PRINT_ACTION, self::BULK_CREATE_PRINT_ACTION], true)) {
            return $redirectTo;
        }

        $ids = $this->normalizeOrderIds($orderIds);
        if (! current_user_can('edit_shop_orders')) {
            $this->storeBulkNotice($this->emptyBulkSummary(
                __('OctavaWMS bulk labels', 'octavawms'),
                'error',
                __('You are not allowed to manage labels.', 'octavawms')
            ));

            return $redirectTo;
        }

        if ($ids === []) {
            $this->storeBulkNotice($this->emptyBulkSummary(
                __('OctavaWMS bulk labels', 'octavawms'),
                'warning',
                __('No orders selected.', 'octavawms')
            ));

            return $redirectTo;
        }

        $summary = match ($action) {
            self::BULK_CREATE_ACTION => $this->bulkCreateLabels($ids, __('OctavaWMS bulk label creation', 'octavawms')),
            self::BULK_PRINT_ACTION => $this->bulkPrintLabels($ids, __('OctavaWMS bulk label printing', 'octavawms')),
            self::BULK_CREATE_PRINT_ACTION => $this->bulkCreateAndPrintLabels($ids),
            default => $this->emptyBulkSummary(__('OctavaWMS bulk labels', 'octavawms'), 'warning', ''),
        };

        $this->storeBulkNotice($summary);

        return $redirectTo;
    }

    /**
     * @return bool Whether label storage succeeded
     */
    private function executeLabelGeneration(WC_Order $order): bool
    {
        return $this->generateLabelForOrder($order)['status'] === 'created';
    }

    /**
     * @return array{status: 'created'|'failed', message: string}
     */
    private function generateLabelForOrder(WC_Order $order): array
    {
        $externalOrderId = $this->resolveExtIdForLabelRequest($order);
        $backendOrder = null;
        foreach (WooOrderExtId::lookupCandidates($order) as $extId) {
            $found = $this->apiClient->findOrderByExtId($extId);
            if ($found !== null) {
                $backendOrder = $found;
                break;
            }
        }

        $weightRaw = WooOrderWeights::contentsWeightTotal($order);
        $weightUnit = (string) get_option('woocommerce_weight_unit', 'kg');
        $weightGrams = max(1, (int) round(WooOrderWeights::toGrams($weightRaw, $weightUnit)));

        $result = $this->labelService->requestLabel(
            $externalOrderId,
            $weightGrams,
            100,
            100,
            100,
            (int) $order->get_id(),
            $backendOrder,
            WooOrderExtId::lookupCandidates($order)
        );

        if ($result['status'] !== 'success') {
            $order->add_order_note(sprintf(
                __('OctavaWMS label generation failed: %s', 'octavawms'),
                $result['message'] ?? 'unknown error'
            ));
            $order->save();

            return ['status' => 'failed', 'message' => (string) ($result['message'] ?? __('Unknown error.', 'octavawms'))];
        }

        $this->storeLabelResult($order, $result);
        $order->save();

        $downloadLink = $this->labelMetaBox->buildDownloadMarkup(
            (int) $order->get_id(),
            (string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true),
            (string) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true)
        );
        $order->add_order_note(
            'OctavaWMS label generated successfully. ' . wp_strip_all_tags($downloadLink)
        );
        $order->save();

        return ['status' => 'created', 'message' => __('Label created.', 'octavawms')];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function storeLabelResult(WC_Order $order, array $result): void
    {
        if (! empty($result['label_url'])) {
            $order->update_meta_data(LabelService::ORDER_META_LABEL_URL, $result['label_url']);
            $order->delete_meta_data(LabelService::ORDER_META_LABEL_FILE);
        }

        if (! empty($result['label_file'])) {
            $order->update_meta_data(LabelService::ORDER_META_LABEL_FILE, $result['label_file']);
            $order->delete_meta_data(LabelService::ORDER_META_LABEL_URL);
        }
    }

    private function hasStoredLabel(WC_Order $order): bool
    {
        return trim((string) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true)) !== ''
            || trim((string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true)) !== '';
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<string, mixed>
     */
    private function bulkCreateLabels(array $orderIds, string $title): array
    {
        $summary = $this->emptyBulkSummary($title, 'success', '');

        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (! $order instanceof WC_Order) {
                $this->appendBulkRow($summary, $orderId, '#' . (string) $orderId, 'failed', __('Order not found.', 'octavawms'));
                continue;
            }

            if (! current_user_can('edit_shop_orders', $orderId)) {
                $this->appendBulkRow($summary, $orderId, $this->orderDisplayLabel($order), 'failed', __('You are not allowed to edit this order.', 'octavawms'));
                continue;
            }

            if ($this->hasStoredLabel($order)) {
                $this->appendBulkRow($summary, $orderId, $this->orderDisplayLabel($order), 'skipped', __('Order already has a label.', 'octavawms'));
                continue;
            }

            $result = $this->generateLabelForOrder($order);
            $this->appendBulkRow($summary, $orderId, $this->orderDisplayLabel($order), $result['status'], $result['message']);
        }

        return $this->finalizeBulkSummaryTone($summary);
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<string, mixed>
     */
    private function bulkPrintLabels(array $orderIds, string $title): array
    {
        $summary = $this->emptyBulkSummary($title, 'success', '');
        $shipmentIds = [];
        $printableRows = [];
        $senderId = null;

        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (! $order instanceof WC_Order) {
                $this->appendBulkRow($summary, $orderId, '#' . (string) $orderId, 'not_printable', __('Order not found.', 'octavawms'));
                continue;
            }

            if (! current_user_can('edit_shop_orders', $orderId)) {
                $this->appendBulkRow($summary, $orderId, $this->orderDisplayLabel($order), 'not_printable', __('You are not allowed to edit this order.', 'octavawms'));
                continue;
            }

            $context = $this->resolvePrintableShipment($order);
            if (! $context['ok']) {
                $this->appendBulkRow($summary, $orderId, $this->orderDisplayLabel($order), 'not_printable', $context['message']);
                continue;
            }

            if ($senderId === null && $context['sender_id'] !== null) {
                $senderId = $context['sender_id'];
            }
            $shipmentIds[] = $context['shipment_id'];
            $printableRows[] = [
                'order_id' => $orderId,
                'label' => $this->orderDisplayLabel($order),
            ];
        }

        if ($shipmentIds === []) {
            $summary['message'] = __('No printable labels found for the selected orders.', 'octavawms');

            return $this->finalizeBulkSummaryTone($summary);
        }

        if ($senderId === null) {
            foreach ($printableRows as $row) {
                $this->appendBulkRow($summary, (int) $row['order_id'], (string) $row['label'], 'failed', __('Could not resolve sender for bulk printing.', 'octavawms'));
            }
            $summary['message'] = __('Could not resolve sender for bulk printing.', 'octavawms');

            return $this->finalizeBulkSummaryTone($summary);
        }

        PluginLog::log('debug', 'bulk_labels_print_import_start', [
            'shipment_ids' => $shipmentIds,
            'sender_id' => $senderId,
            'printable_count' => count($printableRows),
        ]);

        $import = $this->apiClient->importBulkLabels($shipmentIds, $senderId, true);
        PluginLog::log('debug', 'bulk_labels_print_import_response', [
            'ok' => $import['ok'],
            'import_id' => $import['import_id'] ?? null,
            'file_url' => $import['file_url'] ?? null,
            'state' => $import['state'] ?? null,
            'message' => $import['message'] ?? null,
            'status' => $import['status'] ?? null,
        ]);

        if (! $import['ok']) {
            $message = (string) ($import['message'] ?? __('Bulk label import failed.', 'octavawms'));
            foreach ($printableRows as $row) {
                $this->appendBulkRow($summary, (int) $row['order_id'], (string) $row['label'], 'failed', $message);
            }
            $summary['message'] = $message;

            return $this->finalizeBulkSummaryTone($summary);
        }

        $readyImport = $import;
        if (empty($readyImport['file_url']) && ! empty($readyImport['import_id'])) {
            $readyImport = $this->waitForBulkLabelImport((int) $readyImport['import_id']);
            PluginLog::log('debug', 'bulk_labels_print_import_poll_result', [
                'ok' => $readyImport['ok'],
                'import_id' => $readyImport['import_id'] ?? null,
                'file_url' => $readyImport['file_url'] ?? null,
                'state' => $readyImport['state'] ?? null,
                'message' => $readyImport['message'] ?? null,
                'status' => $readyImport['status'] ?? null,
            ]);
        }

        if (! empty($readyImport['file_url'])) {
            foreach ($printableRows as $row) {
                $this->appendBulkRow($summary, (int) $row['order_id'], (string) $row['label'], 'printed', __('Added to merged label PDF.', 'octavawms'));
            }
            $summary['download_url'] = (string) $readyImport['file_url'];
            $summary['import_id'] = $readyImport['import_id'] ?? $import['import_id'] ?? null;
            $summary['message'] = __('Merged label PDF is ready.', 'octavawms');

            return $this->finalizeBulkSummaryTone($summary);
        }

        if (($readyImport['state'] ?? null) === 'error') {
            $message = (string) ($readyImport['message'] ?? __('Bulk label import failed.', 'octavawms'));
            foreach ($printableRows as $row) {
                $this->appendBulkRow($summary, (int) $row['order_id'], (string) $row['label'], 'failed', $message);
            }
            $summary['message'] = $message;

            return $this->finalizeBulkSummaryTone($summary);
        }

        $importId = $readyImport['import_id'] ?? $import['import_id'] ?? null;
        foreach ($printableRows as $row) {
            $this->appendBulkRow($summary, (int) $row['order_id'], (string) $row['label'], 'pending', __('Merged PDF is still being prepared.', 'octavawms'));
        }
        $summary['import_id'] = $importId;
        $summary['message'] = $importId !== null
            ? sprintf(__('Merged PDF is still being prepared. Import ID: %d.', 'octavawms'), (int) $importId)
            : __('Merged PDF is still being prepared.', 'octavawms');

        return $this->finalizeBulkSummaryTone($summary);
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<string, mixed>
     */
    private function bulkCreateAndPrintLabels(array $orderIds): array
    {
        $create = $this->bulkCreateLabels($orderIds, __('OctavaWMS create and print labels', 'octavawms'));
        $print = $this->bulkPrintLabels($orderIds, __('OctavaWMS create and print labels', 'octavawms'));
        $summary = $this->emptyBulkSummary(__('OctavaWMS create and print labels', 'octavawms'), 'success', '');
        $summary['rows'] = array_merge($create['rows'] ?? [], $print['rows'] ?? []);
        $summary['download_url'] = $print['download_url'] ?? null;
        $summary['import_id'] = $print['import_id'] ?? null;
        $summary['message'] = (string) ($print['message'] ?? $create['message'] ?? '');
        foreach ($summary['rows'] as $row) {
            if (isset($row['status']) && is_string($row['status'])) {
                $summary['counts'][$row['status']] = (int) ($summary['counts'][$row['status']] ?? 0) + 1;
            }
        }

        return $this->finalizeBulkSummaryTone($summary);
    }

    /**
     * Match {@see LabelAjax::resolveExtIdForLabelRequest} so order actions and the meta box use the same extId rules.
     */
    private function resolveExtIdForLabelRequest(WC_Order $order): string
    {
        foreach (WooOrderExtId::lookupCandidates($order) as $extId) {
            if ($this->apiClient->findOrderByExtId($extId) !== null) {
                return $extId;
            }
        }

        return WooOrderExtId::importFilterExtId($order);
    }

    /**
     * @return array{ok: true, shipment_id: int, client_ext_id: string, sender_id: int|null}|array{ok: false, message: string}
     */
    private function resolvePrintableShipment(WC_Order $order): array
    {
        $backendOrder = null;
        $matchedCandidate = null;
        $candidates = WooOrderExtId::lookupCandidates($order);
        PluginLog::log('debug', 'bulk_labels_resolve_start', [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'candidates' => $candidates,
            'import_filter_ext_id' => WooOrderExtId::importFilterExtId($order),
            'has_stored_label' => $this->hasStoredLabel($order),
        ]);

        foreach ($candidates as $candidate) {
            $backendOrder = $this->apiClient->findOrderByExtId($candidate);
            if ($backendOrder !== null) {
                $matchedCandidate = $candidate;
                break;
            }
        }

        if ($backendOrder === null) {
            PluginLog::log('debug', 'bulk_labels_resolve_no_backend_order', [
                'order_id' => $order->get_id(),
                'candidates' => $candidates,
            ]);

            return ['ok' => false, 'message' => __('Order not found in OctavaWMS.', 'octavawms')];
        }

        PluginLog::log('debug', 'bulk_labels_resolve_backend_order', [
            'order_id' => $order->get_id(),
            'matched_candidate' => $matchedCandidate,
            'backend_order_id' => $backendOrder['id'] ?? null,
            'backend_order_ext_id' => $backendOrder['extId'] ?? $backendOrder['ext_id'] ?? null,
        ]);

        $shipments = $this->apiClient->findShipmentsForConnector($backendOrder, $candidates);
        PluginLog::log('debug', 'bulk_labels_resolve_shipments', [
            'order_id' => $order->get_id(),
            'shipment_count' => count($shipments),
            'shipment_ids' => array_values(array_filter(array_map(static function (mixed $shipment): ?int {
                if (! is_array($shipment) || ! isset($shipment['id']) || ! is_numeric($shipment['id'])) {
                    return null;
                }

                return (int) $shipment['id'];
            }, $shipments))),
        ]);

        $shipment = $shipments[0] ?? null;
        if (! is_array($shipment) || ! isset($shipment['id']) || ! is_numeric($shipment['id'])) {
            return ['ok' => false, 'message' => __('No shipment found for this order.', 'octavawms')];
        }

        $shipmentId = (int) $shipment['id'];
        $tasks = $this->apiClient->findPreprocessingTasksForShipment($shipmentId);
        $taskId = isset($tasks['task_id']) && is_numeric($tasks['task_id']) ? (int) $tasks['task_id'] : 0;
        PluginLog::log('debug', 'bulk_labels_resolve_tasks', [
            'order_id' => $order->get_id(),
            'shipment_id' => $shipmentId,
            'tasks_ok' => $tasks['ok'] ?? null,
            'task_id' => $tasks['task_id'] ?? null,
            'queue_id' => $tasks['queue_id'] ?? null,
        ]);

        if ($taskId <= 0) {
            return ['ok' => false, 'message' => __('No label task found for this order.', 'octavawms')];
        }

        $detail = $this->apiClient->getShipmentById($shipmentId);
        $senderId = BackendApiClient::extractSenderIdFromDeliveryRequestDetail($detail);
        PluginLog::log('debug', 'bulk_labels_resolve_printable', [
            'order_id' => $order->get_id(),
            'shipment_id' => $shipmentId,
            'task_id' => $taskId,
            'sender_id' => $senderId,
            'client_ext_id' => WooOrderExtId::importFilterExtId($order),
        ]);

        return [
            'ok' => true,
            'shipment_id' => $shipmentId,
            'client_ext_id' => WooOrderExtId::importFilterExtId($order),
            'sender_id' => $senderId,
        ];
    }

    /**
     * @return array{ok: bool, import_id: int|null, file_url: string|null, state: string|null, message?: string, status?: int, data?: mixed}
     */
    private function waitForBulkLabelImport(int $importId): array
    {
        $last = ['ok' => true, 'import_id' => $importId, 'file_url' => null, 'state' => null];
        for ($i = 0; $i < self::BULK_IMPORT_POLL_ATTEMPTS; $i++) {
            $last = $this->apiClient->getImportStatus($importId);
            if (! empty($last['file_url']) || ($last['state'] ?? null) === 'error') {
                return $last;
            }
            if ($i < self::BULK_IMPORT_POLL_ATTEMPTS - 1) {
                sleep(self::BULK_IMPORT_POLL_SLEEP_SECONDS);
            }
        }

        return $last;
    }

    /**
     * @param array<int|string> $orderIds
     *
     * @return list<int>
     */
    private function normalizeOrderIds(array $orderIds): array
    {
        $out = [];
        foreach ($orderIds as $orderId) {
            if (! is_numeric($orderId)) {
                continue;
            }
            $id = (int) $orderId;
            if ($id > 0 && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @return array{title: string, tone: string, message: string, counts: array<string, int>, rows: list<array{order_id: int, label: string, status: string, message: string}>, download_url?: string|null, import_id?: int|null}
     */
    private function emptyBulkSummary(string $title, string $tone, string $message): array
    {
        return [
            'title' => $title,
            'tone' => $tone,
            'message' => $message,
            'counts' => [],
            'rows' => [],
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function appendBulkRow(array &$summary, int $orderId, string $label, string $status, string $message): void
    {
        if (! isset($summary['rows']) || ! is_array($summary['rows'])) {
            $summary['rows'] = [];
        }
        if (! isset($summary['counts']) || ! is_array($summary['counts'])) {
            $summary['counts'] = [];
        }

        $summary['rows'][] = [
            'order_id' => $orderId,
            'label' => $label,
            'status' => $status,
            'message' => $message,
        ];
        $summary['counts'][$status] = (int) ($summary['counts'][$status] ?? 0) + 1;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    private function finalizeBulkSummaryTone(array $summary): array
    {
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $failed = (int) ($counts['failed'] ?? 0);
        $notPrintable = (int) ($counts['not_printable'] ?? 0);
        $pending = (int) ($counts['pending'] ?? 0);
        $successes = (int) ($counts['created'] ?? 0) + (int) ($counts['printed'] ?? 0);

        if ($failed > 0 || $notPrintable > 0 || $pending > 0) {
            $summary['tone'] = $successes > 0 || (int) ($counts['skipped'] ?? 0) > 0 ? 'warning' : 'error';
        } else {
            $summary['tone'] = 'success';
        }

        return $summary;
    }

    private function orderDisplayLabel(WC_Order $order): string
    {
        $number = trim((string) $order->get_order_number());

        return $number !== '' ? '#' . $number : '#' . (string) $order->get_id();
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function storeBulkNotice(array $summary): void
    {
        set_transient($this->bulkNoticeTransientKey(), $summary, 120);
    }

    public function renderBulkNotice(): void
    {
        $key = $this->bulkNoticeTransientKey();
        $summary = get_transient($key);
        if (! is_array($summary)) {
            return;
        }
        delete_transient($key);

        $tone = isset($summary['tone']) && is_string($summary['tone']) ? $summary['tone'] : 'info';
        $noticeClass = match ($tone) {
            'success' => 'notice-success',
            'error' => 'notice-error',
            default => 'notice-warning',
        };
        $title = isset($summary['title']) && is_string($summary['title']) ? $summary['title'] : __('OctavaWMS bulk labels', 'octavawms');
        $message = isset($summary['message']) && is_string($summary['message']) ? $summary['message'] : '';
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $rows = is_array($summary['rows'] ?? null) ? $summary['rows'] : [];
        $parts = [];
        foreach (['created', 'skipped', 'printed', 'pending', 'not_printable', 'failed'] as $keyPart) {
            $count = (int) ($counts[$keyPart] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $parts[] = sprintf('%s: %d', $this->bulkStatusLabel($keyPart), $count);
        }

        echo '<div class="notice ' . esc_attr($noticeClass) . ' is-dismissible">';
        echo '<p><strong>' . esc_html($title) . '</strong>';
        if ($parts !== []) {
            echo ' &mdash; ' . esc_html(implode(', ', $parts));
        }
        echo '</p>';
        if ($message !== '') {
            echo '<p>' . esc_html($message) . '</p>';
        }
        if (! empty($summary['download_url']) && is_string($summary['download_url'])) {
            echo '<p><a class="button button-primary" href="' . esc_url($summary['download_url']) . '" target="_blank" rel="noopener">' . esc_html__('Download labels PDF', 'octavawms') . '</a></p>';
        }
        if (! empty($summary['import_id']) && empty($summary['download_url'])) {
            echo '<p>' . esc_html(sprintf(__('Import ID: %d', 'octavawms'), (int) $summary['import_id'])) . '</p>';
        }
        if ($rows !== []) {
            echo '<ul style="margin-left:1em;list-style:disc;">';
            foreach (array_slice($rows, 0, 25) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : '';
                $status = isset($row['status']) && is_string($row['status']) ? $row['status'] : '';
                $rowMessage = isset($row['message']) && is_string($row['message']) ? $row['message'] : '';
                echo '<li>' . esc_html(trim($label . ' - ' . $this->bulkStatusLabel($status) . ': ' . $rowMessage)) . '</li>';
            }
            if (count($rows) > 25) {
                echo '<li>' . esc_html(sprintf(__('And %d more orders.', 'octavawms'), count($rows) - 25)) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private function bulkNoticeTransientKey(): string
    {
        return self::BULK_NOTICE_TRANSIENT_PREFIX . (string) get_current_user_id();
    }

    private function bulkStatusLabel(string $status): string
    {
        return match ($status) {
            'created' => __('Created', 'octavawms'),
            'skipped' => __('Skipped', 'octavawms'),
            'printed' => __('Printed', 'octavawms'),
            'pending' => __('Pending', 'octavawms'),
            'not_printable' => __('Not printable', 'octavawms'),
            'failed' => __('Failed', 'octavawms'),
            default => $status,
        };
    }

    public function handleDownloadLabelAction(): void
    {
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;

        if (! $orderId || ! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Unauthorized label download request.', 'octavawms'));
        }

        check_admin_referer('octavawms_download_label_' . $orderId);

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'octavawms'));
        }

        $inline = ! empty($_GET['inline']);
        $filePath = (string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);
        if ($filePath !== '' && file_exists($filePath) && is_readable($filePath)) {
            $content = (string) file_get_contents($filePath);
            [$content, $decodedMime] = self::decodeDataUriIfNeeded($content);
            $mime = $decodedMime ?? self::mimeTypeForFilePath($filePath);
            $ext = $decodedMime !== null ? self::mimeToExt($decodedMime) : self::fileExtension($filePath);
            $fileBase = 'order-' . (string) $orderId . '-label.' . $ext;

            nocache_headers();
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fileBase . '"');
            header('Content-Length: ' . (string) strlen($content));
            echo $content;
            exit;
        }

        $shipmentId = isset($_GET['shipment_id']) ? absint(wp_unslash($_GET['shipment_id'])) : 0;
        if ($shipmentId <= 0) {
            wp_die(esc_html__('Label file unavailable.', 'octavawms'));
        }

        $backendOrder = null;
        foreach (WooOrderExtId::lookupCandidates($order) as $candidate) {
            $backendOrder = $this->apiClient->findOrderByExtId($candidate);
            if ($backendOrder !== null) {
                break;
            }
        }
        $shipments = $backendOrder !== null
            ? $this->apiClient->findShipmentsForConnector($backendOrder, WooOrderExtId::lookupCandidates($order))
            : [];
        $shipment = null;
        foreach ($shipments as $candidateShipment) {
            if (is_array($candidateShipment) && isset($candidateShipment['id']) && (int) $candidateShipment['id'] === $shipmentId) {
                $shipment = $candidateShipment;
                break;
            }
        }
        if ($shipment === null) {
            wp_die(esc_html__('Invalid shipment.', 'octavawms'));
        }

        $tasks = $this->apiClient->findPreprocessingTasksForShipment($shipmentId);
        $taskId = isset($tasks['task_id']) && is_numeric($tasks['task_id']) ? (int) $tasks['task_id'] : 0;
        if ($taskId <= 0) {
            wp_die(esc_html__('Label file unavailable.', 'octavawms'));
        }

        $download = $this->apiClient->downloadPreprocessingTaskLabel($taskId);
        $body = isset($download['pdf']) && is_string($download['pdf']) ? $download['pdf'] : '';
        if (! $download['ok'] || $body === '') {
            wp_die(esc_html__('Label file unavailable.', 'octavawms'));
        }

        [$body, $decodedMime] = self::decodeDataUriIfNeeded($body);
        $mime = $decodedMime
            ?? (isset($download['content_type']) && is_string($download['content_type']) && $download['content_type'] !== ''
                ? $download['content_type']
                : 'application/pdf');
        $ext = self::mimeToExt($mime);
        $fileBase = 'order-' . (string) $orderId . '-label.' . $ext;

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fileBase . '"');
        header('Content-Length: ' . (string) strlen($body));
        echo $body;
        exit;
    }

    private function orderEditUrl(int $orderId, string $state): string
    {
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order && function_exists('wc_get_order_edit_url')) {
                return add_query_arg('octavawms_label', $state, (string) wc_get_order_edit_url($order->get_id()));
            }
        }

        return add_query_arg([
            'post' => $orderId,
            'action' => 'edit',
            'octavawms_label' => $state,
        ], admin_url('post.php'));
    }


    private static function fileExtension(string $filePath): string
    {
        $base = (string) pathinfo($filePath, PATHINFO_EXTENSION);
        $base = preg_replace('/[^a-z0-9]/i', '', $base) ?? '';

        return $base !== '' ? strtolower($base) : 'pdf';
    }

    private static function mimeTypeForFilePath(string $filePath): string
    {
        $ext = self::fileExtension($filePath);
        $map = [
            'pdf' => 'application/pdf',
            'zpl' => 'text/plain',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
        ];

        if (class_exists('finfo') && is_readable($filePath)) {
            $f = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $f->file($filePath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * If $raw is a data URI (data:<mime>;base64,<data>), decode and return
     * [decoded_binary, mime_type].  Otherwise return [$raw, null].
     *
     * @return array{0: string, 1: string|null}
     */
    private static function decodeDataUriIfNeeded(string $raw): array
    {
        $trimmed = ltrim($raw);
        if (! str_starts_with($trimmed, 'data:')) {
            return [$raw, null];
        }
        $commaPos = strpos($trimmed, ',');
        if ($commaPos === false) {
            return [$raw, null];
        }
        $meta = substr($trimmed, 5, $commaPos - 5); // e.g. "application/pdf;base64"
        $data = substr($trimmed, $commaPos + 1);
        if (! str_contains($meta, 'base64')) {
            return [$raw, null];
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false || $decoded === '') {
            return [$raw, null];
        }
        $mime = trim(explode(';', $meta)[0]);

        return [$decoded, $mime !== '' ? $mime : null];
    }

    private static function mimeToExt(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'text/plain' => 'txt',
            default => 'pdf',
        };
    }
}
