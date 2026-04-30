<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Admin;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\WooOrderExtId;
use WC_Order;

class LabelAjax
{
    private BackendApiClient $apiClient;

    private LabelService $labelService;

    private LabelMetaBox $metaBox;

    public function __construct(BackendApiClient $apiClient, LabelService $labelService, LabelMetaBox $metaBox)
    {
        $this->apiClient = $apiClient;
        $this->labelService = $labelService;
        $this->metaBox = $metaBox;
    }

    public function register(): void
    {
        add_action('wp_ajax_octavawms_order_status', [$this, 'handleAjaxOrderStatus']);
        add_action('wp_ajax_octavawms_upload_order', [$this, 'handleAjaxUploadOrder']);
        add_action('wp_ajax_octavawms_generate_label', [$this, 'handleAjaxGenerateLabel']);
    }

    public function handleAjaxOrderStatus(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }

        check_ajax_referer('octavawms_order_status_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }

        [$backendOrder, $resolvedExtId] = $this->findBackendOrderAndResolvedExtId($order);

        if ($backendOrder !== null) {
            $this->maybePersistCanonicalExtId($order, $backendOrder);
        }

        $shipments = $backendOrder !== null && $resolvedExtId !== null
            ? $this->apiClient->findShipmentsByExtId($resolvedExtId)
            : [];
        $first = $shipments[0] ?? null;
        $shipmentPayload = null;
        if (is_array($first) && isset($first['id'])) {
            $shipmentPayload = [
                'id' => $first['id'],
                'state' => isset($first['state']) && is_string($first['state']) ? $first['state'] : '',
            ];
        }

        $labelUrl = (string) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true);
        $labelFile = (string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);
        $hasLocal = ($labelUrl !== '' || $labelFile !== '');

        $downloadUrl = '';
        if ($hasLocal) {
            $markup = $this->metaBox->buildDownloadMarkup($orderId, $labelFile, $labelUrl);
            if (preg_match('/href="([^"]+)"/', $markup, $m)) {
                $downloadUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        wp_send_json_success([
            'has_order' => $backendOrder !== null,
            'shipment' => $shipmentPayload,
            'has_label_locally' => $hasLocal,
            'download_url' => $downloadUrl,
        ]);
    }

    public function handleAjaxUploadOrder(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }

        check_ajax_referer('octavawms_upload_order_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }

        $sourceId = Options::getSourceId();
        if ($sourceId <= 0) {
            wp_send_json_error(['message' => __('Connect the plugin under WooCommerce → Settings → Integrations first.', 'octavawms')], 400);
        }

        $extId = WooOrderExtId::importFilterExtId($order);

        $result = $this->apiClient->importOrder($extId, $sourceId);
        if (! $result['ok']) {
            wp_send_json_error(['message' => $result['message'] ?? __('Upload failed.', 'octavawms')], 502);
        }

        $data = $result['data'] ?? null;
        if (is_array($data)) {
            $entity = $this->apiClient->extractFirstOrderFromCollectionJson($data);
            $this->maybePersistCanonicalExtIdFromEntity($order, $entity);
        }

        wp_send_json_success(['imported' => true]);
    }

    /**
     * AJAX handler for "Generate Label" button in the meta box panel.
     *
     * Uses the preprocessing-task pipeline (same as octavawms-app and Shopify extension).
     * Weight is derived from the WooCommerce order's total weight; dimensions default to 100 mm.
     */
    public function handleAjaxGenerateLabel(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }

        check_ajax_referer('octavawms_generate_label_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }

        $extId = $this->resolveExtIdForLabelRequest($order);

        $weightRaw = (float) $order->get_total_weight();
        $weightUnit = (string) get_option('woocommerce_weight_unit', 'kg');
        $weightGrams = max(1, (int) round(self::convertWeightToGrams($weightRaw, $weightUnit)));

        $result = $this->labelService->requestLabel($extId, $weightGrams, 100, 100, 100, $orderId);

        if ($result['status'] !== 'success') {
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('OctavaWMS label generation failed: %s', 'octavawms'),
                $result['message'] ?? 'unknown error'
            ));
            $order->save();
            wp_send_json_error(['message' => $result['message'] ?? __('Label generation failed.', 'octavawms')], 502);
        }

        if (! empty($result['label_url'])) {
            $order->update_meta_data(LabelService::ORDER_META_LABEL_URL, $result['label_url']);
            $order->delete_meta_data(LabelService::ORDER_META_LABEL_FILE);
        }

        if (! empty($result['label_file'])) {
            $order->update_meta_data(LabelService::ORDER_META_LABEL_FILE, $result['label_file']);
            $order->delete_meta_data(LabelService::ORDER_META_LABEL_URL);
        }

        $downloadLink = $this->metaBox->buildDownloadMarkup(
            $orderId,
            (string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true),
            (string) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true)
        );
        $order->add_order_note('OctavaWMS label generated. ' . wp_strip_all_tags($downloadLink));
        $order->save();

        $downloadUrl = '';
        if (preg_match('/href="([^"]+)"/', $downloadLink, $m)) {
            $downloadUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        wp_send_json_success(['has_label' => true, 'download_url' => $downloadUrl]);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function findBackendOrderAndResolvedExtId(WC_Order $order): array
    {
        foreach (WooOrderExtId::lookupCandidates($order) as $extId) {
            $backendOrder = $this->apiClient->findOrderByExtId($extId);
            if ($backendOrder !== null) {
                return [$backendOrder, $extId];
            }
        }

        return [null, null];
    }

    /**
     * Prefer an extId that already exists in OctavaWMS; fall back to the import filter extId.
     */
    private function resolveExtIdForLabelRequest(WC_Order $order): string
    {
        [, $resolved] = $this->findBackendOrderAndResolvedExtId($order);

        return $resolved ?? WooOrderExtId::importFilterExtId($order);
    }

    /**
     * @param array<string, mixed>|null $entity
     */
    private function maybePersistCanonicalExtIdFromEntity(WC_Order $order, ?array $entity): void
    {
        if ($entity === null) {
            return;
        }
        $canonical = '';
        if (isset($entity['extId']) && is_string($entity['extId'])) {
            $canonical = trim($entity['extId']);
        } elseif (isset($entity['ext_id']) && is_string($entity['ext_id'])) {
            $canonical = trim($entity['ext_id']);
        }
        if ($canonical === '') {
            return;
        }
        $stored = trim((string) $order->get_meta('_octavawms_external_order_id', true));
        if ($stored === $canonical) {
            return;
        }
        $order->update_meta_data('_octavawms_external_order_id', $canonical);
        $order->save();
    }

    /**
     * @param array<string, mixed> $backendOrder
     */
    private function maybePersistCanonicalExtId(WC_Order $order, array $backendOrder): void
    {
        $this->maybePersistCanonicalExtIdFromEntity($order, $backendOrder);
    }

    /**
     * Convert a weight value to grams based on the WooCommerce store's weight unit setting.
     */
    private static function convertWeightToGrams(float $weight, string $unit): float
    {
        return match (strtolower($unit)) {
            'kg' => $weight * 1000.0,
            'lbs' => $weight * 453.592,
            'oz' => $weight * 28.3495,
            default => $weight,
        };
    }
}
