<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Admin;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\PluginLog;
use OctavaWMS\WooCommerce\WooOrderExtId;
use OctavaWMS\WooCommerce\WooOrderWeights;
use WC_Order;

class LabelAjax
{
    private const UI_DEFAULT_PLACE_WEIGHT = 1.0;

    private const UI_DEFAULT_PLACE_DIMENSION_MM = 100.0;

    /** @see shopify-octava-wms MANUAL_STRATEGY */
    public const DELIVERY_STRATEGY_MANUAL = '__manual__';

    /** PATCH body `{ "state": "pending_queued" }` when shipment is `pending_error` (edit-shipment Retry). */
    public const PATCH_KIND_RETRY_PENDING_ERROR = 'retry_pending_error';

    /** PATCH body `{ "state": "ending_queued" }` so the backend can re-resolve rates/carrier assignment. */
    public const PATCH_KIND_REQUEUE_ENDING_QUEUED = 'requeue_ending_queued';

    private const STRATEGY_JSON_OFFICE = '{"updateData":{"eav":{"delivery-request-service-point-select":{"mode":"closest","criteria":{"type":"service_point"}}}}}';

    private const STRATEGY_JSON_LOCKER = '{"updateData":{"eav":{"delivery-request-service-point-select":{"mode":"closest","criteria":{"type":"self_service_point"}}}}}';

    private const STRATEGY_JSON_OFFICE_AND_LOCKER = '{"updateData":{"eav":{"delivery-request-service-point-select":{"mode":"closest","criteria":{"type":["service_point","self_service_point"]}}}}}';

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
        add_action('wp_ajax_octavawms_shipment_detail', [$this, 'handleAjaxShipmentDetail']);
        add_action('wp_ajax_octavawms_service_points', [$this, 'handleAjaxServicePoints']);
        add_action('wp_ajax_octavawms_save_service_point', [$this, 'handleAjaxSaveServicePoint']);
        add_action('wp_ajax_octavawms_patch_shipment', [$this, 'handleAjaxPatchShipment']);
        add_action('wp_ajax_octavawms_delivery_services', [$this, 'handleAjaxDeliveryServices']);
        add_action('wp_ajax_octavawms_localities', [$this, 'handleAjaxLocalities']);
        add_action('wp_ajax_octavawms_places', [$this, 'handleAjaxPlaces']);
        add_action('wp_ajax_octavawms_add_place', [$this, 'handleAjaxAddPlace']);
        add_action('wp_ajax_octavawms_update_place', [$this, 'handleAjaxUpdatePlace']);
        add_action('wp_ajax_octavawms_delete_place', [$this, 'handleAjaxDeletePlace']);
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

        $candidates = WooOrderExtId::lookupCandidates($order);
        $shipments = $backendOrder !== null
            ? $this->apiClient->findShipmentsForConnector($backendOrder, $candidates)
            : [];
        $first = $shipments[0] ?? null;
        $shipmentPayload = null;
        if (is_array($first) && isset($first['id'])) {
            $st = isset($first['state']) && is_string($first['state']) ? $first['state'] : '';
            $shipmentPayload = [
                'id' => $first['id'],
                'state' => $st,
            ];
            if ($st === 'pending_error') {
                $em = PluginLog::shipmentErrorMessageFromApiShipment($first);
                if ($em !== '') {
                    $shipmentPayload['error_message'] = $em;
                }
            }
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

        $shipmentIdForSections = is_array($first) && isset($first['id']) ? (int) $first['id'] : 0;

        $weightRaw = WooOrderWeights::contentsWeightTotal($order);
        $weightUnit = (string) get_option('woocommerce_weight_unit', 'kg');
        $defaultGrams = max(1, (int) round(WooOrderWeights::toGrams($weightRaw, $weightUnit)));

        $codPayload = ['is_cod' => false];
        if ($order->get_payment_method() === 'cod') {
            $formatted = wp_strip_all_tags(
                html_entity_decode(
                    (string) wc_price((string) $order->get_total(), ['currency' => $order->get_currency()]),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );
            $codPayload = [
                'is_cod' => true,
                'label' => __('Cash on delivery', 'octavawms'),
                'formatted_total' => $formatted,
            ];
            $pmTitle = trim((string) $order->get_payment_method_title());
            if ($pmTitle !== '') {
                $codPayload['payment_method_title'] = $pmTitle;
            }
        }

        wp_send_json_success([
            'has_order' => $backendOrder !== null,
            'shipment' => $shipmentPayload,
            'shipment_id' => $shipmentIdForSections,
            'has_label_locally' => $hasLocal,
            'download_url' => $downloadUrl,
            'label_defaults' => [
                'weight_grams' => $defaultGrams,
                'dim_x' => 100,
                'dim_y' => 100,
                'dim_z' => 100,
            ],
            'cod' => $codPayload,
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
     * Optional POST: weight_grams, dim_x, dim_y, dim_z (falls back to order weight and 100 mm cubes).
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
        [$backendOrder] = $this->findBackendOrderAndResolvedExtId($order);

        $shipmentIdPost = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        $fromPlaces = ! empty($_POST['from_places']);
        $useShipmentPlaces =
            $fromPlaces
            && $shipmentIdPost > 0
            && $this->shipmentBelongsToOrder($order, $shipmentIdPost);

        if ($useShipmentPlaces) {
            $agg = $this->aggregateLabelMeasuresFromShipmentPlaces($shipmentIdPost);
            if ($agg === null) {
                wp_send_json_error([
                    'message' => __('Add at least one box before generating a label.', 'octavawms'),
                ], 400);
            }
            /** @var array{0:int,1:int,2:int,3:int} $agg */
            [$weightGrams, $dimX, $dimY, $dimZ] = $agg;
        } else {
            [$weightGrams, $dimX, $dimY, $dimZ] = $this->parseLabelMeasurementsFromPost($order);
        }

        $result = $this->labelService->requestLabel(
            $extId,
            $weightGrams,
            $dimX,
            $dimY,
            $dimZ,
            $orderId,
            $backendOrder,
            WooOrderExtId::lookupCandidates($order)
        );

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

    public function handleAjaxShipmentDetail(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment.', 'octavawms')], 400);
        }

        $detail = $this->apiClient->getShipmentById($shipmentId);
        if ($detail === null) {
            wp_send_json_error(['message' => __('Could not load shipment.', 'octavawms')], 502);
        }

        wp_send_json_success(['detail' => $this->buildShipmentDetailPayload($detail)]);
    }

    public function handleAjaxServicePoints(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment.', 'octavawms')], 400);
        }

        $localityId = isset($_POST['locality_id']) ? absint(wp_unslash($_POST['locality_id'])) : 0;
        $deliveryServiceId = isset($_POST['delivery_service_id']) ? absint(wp_unslash($_POST['delivery_service_id'])) : 0;
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash((string) $_POST['search'])) : '';
        $noLockers = ! empty($_POST['no_lockers']);
        $spTypeFilter = isset($_POST['sp_type_filter']) ? sanitize_key((string) wp_unslash($_POST['sp_type_filter'])) : '';

        $params = [
            'page' => 1,
        ];
        if ($localityId > 0) {
            $params['localityId'] = $localityId;
        }
        if ($deliveryServiceId > 0) {
            $params['deliveryServiceId'] = $deliveryServiceId;
        }
        if ($noLockers) {
            $params['servicePointType'] = 'service_point';
        } elseif ($spTypeFilter === 'service_point' || $spTypeFilter === 'self_service_point') {
            $params['servicePointType'] = $spTypeFilter;
        }
        if ($search !== '') {
            $params['search'] = $search;
        }

        $params['perPage'] = 250;
        $result = $this->apiClient->fetchServicePoints($params);
        $items = [];
        foreach ($result['items'] as $row) {
            if (is_array($row)) {
                $items[] = $this->simplifyServicePointRow($row);
            }
        }

        wp_send_json_success(['items' => $items]);
    }

    public function handleAjaxSaveServicePoint(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        $servicePointId = isset($_POST['service_point_id']) ? absint(wp_unslash($_POST['service_point_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || $servicePointId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment or service point.', 'octavawms')], 400);
        }

        $detail = $this->apiClient->getShipmentById($shipmentId);
        if ($detail === null) {
            wp_send_json_error(['message' => __('Could not load shipment.', 'octavawms')], 502);
        }
        $selfHref = $this->extractShipmentSelfHref($detail);
        $payload = ['servicePoint' => $servicePointId];
        $patch = $selfHref !== ''
            ? $this->apiClient->patchShipmentAtHref($selfHref, $payload)
            : $this->apiClient->patchShipment($shipmentId, $payload);
        if (! $patch['ok']) {
            wp_send_json_error(['message' => $patch['message'] ?? __('Could not update service point.', 'octavawms')], 502);
        }

        $fresh = $this->apiClient->getShipmentById($shipmentId);
        wp_send_json_success([
            'detail' => $fresh !== null ? $this->buildShipmentDetailPayload($fresh) : $this->buildShipmentDetailPayload($detail),
        ]);
    }

    public function handleAjaxPatchShipment(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        $kind = isset($_POST['patch_kind']) ? sanitize_key((string) wp_unslash($_POST['patch_kind'])) : '';
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment.', 'octavawms')], 400);
        }

        $detail = $this->apiClient->getShipmentById($shipmentId);
        if ($detail === null) {
            wp_send_json_error(['message' => __('Could not load shipment.', 'octavawms')], 502);
        }

        $selfHref = $this->extractShipmentSelfHref($detail);
        /** @var array<string, mixed> $payload */
        $payload = [];

        if ($kind === 'delivery_service') {
            $dsId = isset($_POST['delivery_service_id']) ? absint(wp_unslash($_POST['delivery_service_id'])) : 0;
            if ($dsId <= 0) {
                wp_send_json_error(['message' => __('Choose a delivery carrier.', 'octavawms')], 400);
            }
            $payload['deliveryService'] = $dsId;
            $payload['servicePoint'] = null;
            $payload['rate'] = null;
        } elseif ($kind === 'recipient_locality') {
            $lid = isset($_POST['recipient_locality_id']) ? absint(wp_unslash($_POST['recipient_locality_id'])) : 0;
            $payload['recipientLocality'] = $lid > 0 ? $lid : null;
        } elseif ($kind === 'delivery_strategy') {
            $strategy = isset($_POST['strategy']) ? (string) wp_unslash($_POST['strategy']) : '';
            if (! self::isAllowedStrategySelection($strategy)) {
                wp_send_json_error(['message' => __('Invalid delivery strategy.', 'octavawms')], 400);
            }
            if ($strategy === '' || $strategy === self::DELIVERY_STRATEGY_MANUAL) {
                $payload['eav'] = null;
            } else {
                $parsed = json_decode($strategy, true);
                if (! is_array($parsed)) {
                    wp_send_json_error(['message' => __('Invalid delivery strategy.', 'octavawms')], 400);
                }
                $payload['eav'] = $parsed['updateData']['eav'] ?? null;
                $payload['servicePoint'] = null;
            }
        } elseif ($kind === self::PATCH_KIND_RETRY_PENDING_ERROR) {
            $state = isset($detail['state']) && is_string($detail['state']) ? $detail['state'] : '';
            if ($state !== 'pending_error') {
                wp_send_json_error(['message' => __('Only a failed shipment (pending_error) can be retried this way.', 'octavawms')], 400);
            }
            $payload['state'] = 'pending_queued';
        } elseif ($kind === self::PATCH_KIND_REQUEUE_ENDING_QUEUED) {
            $payload['state'] = 'ending_queued';
        } else {
            wp_send_json_error(['message' => __('Invalid request.', 'octavawms')], 400);
        }

        $patch = $selfHref !== ''
            ? $this->apiClient->patchShipmentAtHref($selfHref, $payload)
            : $this->patchShipmentWithoutSelfHref($shipmentId, $payload);
        if (! $patch['ok']) {
            wp_send_json_error(['message' => $patch['message'] ?? __('Could not update shipment.', 'octavawms')], 502);
        }

        $fresh = $this->apiClient->getShipmentById($shipmentId);
        wp_send_json_success([
            'detail' => $fresh !== null ? $this->buildShipmentDetailPayload($fresh) : $this->buildShipmentDetailPayload($detail),
        ]);
    }

    public function handleAjaxDeliveryServices(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash((string) $_POST['search'])) : '';
        $page = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 1;

        $result = $this->apiClient->fetchDeliveryServicesPage($search, $page);
        $items = [];
        foreach ($result['items'] as $row) {
            if (is_array($row)) {
                $items[] = [
                    'id' => isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0,
                    'name' => isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
                ];
            }
        }

        wp_send_json_success([
            'items' => $items,
            'total_pages' => $result['total_pages'],
        ]);
    }

    public function handleAjaxLocalities(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash((string) $_POST['search'])) : '';
        $page = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 1;
        $exactId = isset($_POST['exact_id']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['exact_id'])) : '';
        $exact = $exactId !== '' ? $exactId : null;

        $result = $this->apiClient->fetchLocalitiesPage($search, $page, $exact);
        $items = [];
        foreach ($result['items'] as $row) {
            if (is_array($row)) {
                $items[] = $this->simplifyLocalityRow($row);
            }
        }

        wp_send_json_success([
            'items' => $items,
            'total_pages' => $result['total_pages'],
        ]);
    }

    public function handleAjaxPlaces(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment.', 'octavawms')], 400);
        }

        $places = $this->apiClient->fetchPlacesForDeliveryRequest($shipmentId);
        $out = [];
        foreach ($places as $p) {
            if (is_array($p)) {
                $row = $this->simplifyPlaceRow($p);
                $pid = isset($row['id']) ? (int) $row['id'] : 0;
                if ($pid > 0 && $this->placeMeasuresNeedUiDefaults($row)) {
                    $ur = $this->apiClient->updatePlace($pid, [
                        'weight' => self::UI_DEFAULT_PLACE_WEIGHT,
                        'dimensions' => [
                            'x' => self::UI_DEFAULT_PLACE_DIMENSION_MM,
                            'y' => self::UI_DEFAULT_PLACE_DIMENSION_MM,
                            'z' => self::UI_DEFAULT_PLACE_DIMENSION_MM,
                        ],
                    ]);
                    if ($ur['ok']) {
                        $row['weight'] = self::UI_DEFAULT_PLACE_WEIGHT;
                        $row['dim_x'] = self::UI_DEFAULT_PLACE_DIMENSION_MM;
                        $row['dim_y'] = self::UI_DEFAULT_PLACE_DIMENSION_MM;
                        $row['dim_z'] = self::UI_DEFAULT_PLACE_DIMENSION_MM;
                    }
                }
                $out[] = $row;
            }
        }

        wp_send_json_success(['places' => $out]);
    }

    public function handleAjaxAddPlace(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment.', 'octavawms')], 400);
        }

        $r = $this->apiClient->addPlace($shipmentId);
        if (! $r['ok']) {
            wp_send_json_error(['message' => $r['message'] ?? __('Could not add place.', 'octavawms')], 502);
        }

        $placeId = 0;
        $data = $r['data'] ?? null;
        if (is_array($data) && isset($data['id']) && is_numeric($data['id'])) {
            $placeId = (int) $data['id'];
        }

        if ($placeId <= 0) {
            wp_send_json_success([
                'ok' => true,
                'place_id' => $placeId,
            ]);
            return;
        }

        $ur = $this->apiClient->updatePlace($placeId, [
            'weight' => self::UI_DEFAULT_PLACE_WEIGHT,
            'dimensions' => [
                'x' => self::UI_DEFAULT_PLACE_DIMENSION_MM,
                'y' => self::UI_DEFAULT_PLACE_DIMENSION_MM,
                'z' => self::UI_DEFAULT_PLACE_DIMENSION_MM,
            ],
        ]);
        if (! $ur['ok']) {
            wp_send_json_error([
                'message' => $ur['message'] ?? __('Box was created but default size could not be applied.', 'octavawms'),
            ], 502);
        }

        wp_send_json_success([
            'ok' => true,
            'place_id' => $placeId,
        ]);
    }

    public function handleAjaxUpdatePlace(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        $placeId = isset($_POST['place_id']) ? absint(wp_unslash($_POST['place_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || $placeId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment or place.', 'octavawms')], 400);
        }
        if (! $this->placeBelongsToShipment($shipmentId, $placeId)) {
            wp_send_json_error(['message' => __('Invalid place.', 'octavawms')], 400);
        }

        $weight = isset($_POST['weight']) ? (float) wp_unslash($_POST['weight']) : 0.0;
        $dimX = isset($_POST['dim_x']) ? (float) wp_unslash($_POST['dim_x']) : 0.0;
        $dimY = isset($_POST['dim_y']) ? (float) wp_unslash($_POST['dim_y']) : 0.0;
        $dimZ = isset($_POST['dim_z']) ? (float) wp_unslash($_POST['dim_z']) : 0.0;

        $r = $this->apiClient->updatePlace($placeId, [
            'weight' => $weight,
            'dimensions' => [
                'x' => $dimX,
                'y' => $dimY,
                'z' => $dimZ,
            ],
        ]);
        if (! $r['ok']) {
            wp_send_json_error(['message' => $r['message'] ?? __('Could not update place.', 'octavawms')], 502);
        }

        wp_send_json_success(['ok' => true]);
    }

    public function handleAjaxDeletePlace(): void
    {
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $shipmentId = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        $placeId = isset($_POST['place_id']) ? absint(wp_unslash($_POST['place_id'])) : 0;
        if ($orderId <= 0 || ! current_user_can('edit_shop_orders', $orderId)) {
            wp_send_json_error(['message' => __('Invalid order.', 'octavawms')], 403);
        }
        check_ajax_referer('octavawms_connector_' . (string) $orderId, 'nonce');

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'octavawms')], 404);
        }
        if ($shipmentId <= 0 || $placeId <= 0 || ! $this->shipmentBelongsToOrder($order, $shipmentId)) {
            wp_send_json_error(['message' => __('Invalid shipment or place.', 'octavawms')], 400);
        }

        $places = $this->apiClient->fetchPlacesForDeliveryRequest($shipmentId);
        $target = null;
        foreach ($places as $p) {
            if (is_array($p) && isset($p['id']) && (int) $p['id'] === $placeId) {
                $target = $p;
                break;
            }
        }
        if ($target === null) {
            wp_send_json_error(['message' => __('Invalid place.', 'octavawms')], 400);
        }
        if (self::placeItemsCount($target) > 0) {
            wp_send_json_error(['message' => __('Cannot remove a place that still has items.', 'octavawms')], 400);
        }

        $r = $this->apiClient->deletePlace($placeId);
        if (! $r['ok']) {
            wp_send_json_error(['message' => $r['message'] ?? __('Could not remove place.', 'octavawms')], 502);
        }

        wp_send_json_success(['ok' => true]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listShipmentsForOrder(WC_Order $order): array
    {
        [$backendOrder] = $this->findBackendOrderAndResolvedExtId($order);
        if ($backendOrder === null) {
            return [];
        }

        return $this->apiClient->findShipmentsForConnector($backendOrder, WooOrderExtId::lookupCandidates($order));
    }

    private function shipmentBelongsToOrder(WC_Order $order, int $shipmentId): bool
    {
        if ($shipmentId <= 0) {
            return false;
        }
        foreach ($this->listShipmentsForOrder($order) as $s) {
            if (isset($s['id']) && (int) $s['id'] === $shipmentId) {
                return true;
            }
        }

        return false;
    }

    private function placeBelongsToShipment(int $shipmentId, int $placeId): bool
    {
        foreach ($this->apiClient->fetchPlacesForDeliveryRequest($shipmentId) as $p) {
            if (is_array($p) && isset($p['id']) && (int) $p['id'] === $placeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function buildShipmentDetailPayload(array $detail): array
    {
        $ds = $detail['_embedded']['deliveryService'] ?? null;
        $deliveryServiceId = null;
        $deliveryServiceName = '';
        if (is_array($ds)) {
            if (isset($ds['id']) && is_numeric($ds['id'])) {
                $deliveryServiceId = (int) $ds['id'];
            }
            if (isset($ds['name']) && is_string($ds['name'])) {
                $deliveryServiceName = $ds['name'];
            }
        }
        $loc = $detail['_embedded']['recipientLocality'] ?? null;
        $localityId = null;
        $localityLabel = '';
        if (is_array($loc)) {
            if (isset($loc['id']) && is_numeric($loc['id'])) {
                $localityId = (int) $loc['id'];
            }
            $name = (string) ($loc['name'] ?? $loc['queryName'] ?? '');
            $area = '';
            $country = '';
            $lemb = $loc['_embedded'] ?? null;
            if (is_array($lemb)) {
                if (isset($lemb['area']['name']) && is_string($lemb['area']['name'])) {
                    $area = $lemb['area']['name'];
                }
                if (isset($lemb['country']['name']) && is_string($lemb['country']['name'])) {
                    $country = $lemb['country']['name'];
                }
            }
            $localityLabel = $this->dedupeGeographicLabel($name, $area, $country);
        }
        $sp = $detail['_embedded']['servicePoint'] ?? null;
        $currentSp = null;
        if (is_array($sp)) {
            $currentSp = $this->simplifyServicePointRow($sp);
        }
        $state = '';
        if (isset($detail['state']) && is_string($detail['state'])) {
            $state = $detail['state'];
        }
        $statusText = '';
        if (isset($detail['status']) && is_string($detail['status'])) {
            $statusText = trim($detail['status']);
        }
        $errorMessage = '';
        if ($state === 'pending_error') {
            $errorMessage = PluginLog::shipmentErrorMessageFromApiShipment($detail);
        }

        $servicePointContext = [];
        $eav = $detail['eav'] ?? null;
        if (is_array($eav)) {
            if (isset($eav['delivery-request-service-point']) && is_string($eav['delivery-request-service-point'])) {
                $ai = trim($eav['delivery-request-service-point']);
                if ($ai !== '') {
                    $servicePointContext['ai_message'] = $ai;
                }
            }
            if (isset($eav['delivery-request-service-point-distance']) && is_numeric($eav['delivery-request-service-point-distance'])) {
                $servicePointContext['distance_m'] = round((float) $eav['delivery-request-service-point-distance'], 2);
            }
        }

        return [
            'delivery_service_id' => $deliveryServiceId,
            'delivery_service_name' => $deliveryServiceName,
            'locality_id' => $localityId,
            'locality_label' => $localityLabel,
            'current_service_point' => $currentSp,
            'service_point_context' => $servicePointContext,
            'shipment_state' => $state,
            'shipment_status_text' => $statusText,
            'shipment_error_message' => $errorMessage,
            'delivery_strategy' => $this->resolveDeliveryStrategySelectValue($detail),
        ];
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function resolveDeliveryStrategySelectValue(array $detail): string
    {
        $eav = $detail['eav'] ?? null;
        $hasSp = false;
        $sp = $detail['_embedded']['servicePoint'] ?? null;
        if (is_array($sp) && isset($sp['id']) && is_numeric($sp['id']) && (int) $sp['id'] > 0) {
            $hasSp = true;
        }
        if (! is_array($eav) || $eav === []) {
            return $hasSp ? self::DELIVERY_STRATEGY_MANUAL : '';
        }
        foreach ([self::STRATEGY_JSON_OFFICE, self::STRATEGY_JSON_LOCKER, self::STRATEGY_JSON_OFFICE_AND_LOCKER] as $json) {
            $want = self::eavPayloadFromStrategyJson($json);
            if ($want !== null && self::deliveryEavMatches($eav, $want)) {
                return $json;
            }
        }

        return $hasSp ? self::DELIVERY_STRATEGY_MANUAL : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function eavPayloadFromStrategyJson(string $json): ?array
    {
        $parsed = json_decode($json, true);
        if (! is_array($parsed)) {
            return null;
        }
        $inner = $parsed['updateData']['eav'] ?? null;

        return is_array($inner) ? $inner : null;
    }

    /**
     * @param array<string, mixed> $saved
     * @param array<string, mixed> $expected
     */
    private static function deliveryEavMatches(array $saved, array $expected): bool
    {
        return self::normalizeEavForCompare($saved) === self::normalizeEavForCompare($expected);
    }

    /**
     * @param array<string, mixed> $eav
     */
    private static function normalizeEavForCompare(array $eav): string
    {
        $key = 'delivery-request-service-point-select';
        if (! isset($eav[$key]) || ! is_array($eav[$key])) {
            return (string) wp_json_encode($eav);
        }
        $copy = $eav;
        $dr = $eav[$key];
        if (! is_array($dr)) {
            return (string) wp_json_encode($eav);
        }
        $crit = $dr['criteria'] ?? null;
        if (is_array($crit) && isset($crit['type']) && is_array($crit['type'])) {
            $types = $crit['type'];
            /** @var list<string> $sorted */
            $sorted = array_map('strval', $types);
            sort($sorted, SORT_STRING);
            $copy[$key] = array_merge($dr, ['criteria' => array_merge($crit, ['type' => $sorted])]);
        }

        return (string) wp_json_encode($copy);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function deliveryStrategyOptionsForScript(): array
    {
        return [
            ['label' => __('Address (to door)', 'octavawms'), 'value' => ''],
            ['label' => __('Office — AI picks closest office', 'octavawms'), 'value' => self::STRATEGY_JSON_OFFICE],
            ['label' => __('Locker — AI picks closest locker', 'octavawms'), 'value' => self::STRATEGY_JSON_LOCKER],
            ['label' => __('Office + locker — AI picks closest', 'octavawms'), 'value' => self::STRATEGY_JSON_OFFICE_AND_LOCKER],
            ['label' => __('Manual — choose service point yourself', 'octavawms'), 'value' => self::DELIVERY_STRATEGY_MANUAL],
        ];
    }

    private static function isAllowedStrategySelection(string $strategy): bool
    {
        if ($strategy === '' || $strategy === self::DELIVERY_STRATEGY_MANUAL) {
            return true;
        }
        foreach ([self::STRATEGY_JSON_OFFICE, self::STRATEGY_JSON_LOCKER, self::STRATEGY_JSON_OFFICE_AND_LOCKER] as $json) {
            if ($strategy === $json) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{ok: bool, data: array<string, mixed>|null, message?: string}
     */
    private function patchShipmentWithoutSelfHref(int $shipmentId, array $payload): array
    {
        $body = $payload;
        if (array_key_exists('deliveryService', $body) || array_key_exists('eav', $body)) {
            $body['state'] = 'pending_queued';
        }

        return $this->apiClient->patchShipment($shipmentId, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function simplifyLocalityRow(array $row): array
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        $name = (string) ($row['name'] ?? $row['queryName'] ?? '');
        $area = '';
        $country = '';
        $emb = $row['_embedded'] ?? null;
        if (is_array($emb)) {
            if (isset($emb['area']['name']) && is_string($emb['area']['name'])) {
                $area = $emb['area']['name'];
            }
            if (isset($emb['country']['name']) && is_string($emb['country']['name'])) {
                $country = $emb['country']['name'];
            }
        }
        $label = $this->dedupeGeographicLabel($name, $area, $country);

        return [
            'id' => $id,
            'name' => $name,
            'label' => $label,
            'area' => $area,
            'country' => $country,
        ];
    }

    private function dedupeGeographicLabel(string $name, string $area, string $country): string
    {
        $parts = [];
        foreach ([$name, $area, $country] as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $last = $parts === [] ? null : $parts[array_key_last($parts)];
            if ($last !== null && strcasecmp($last, $p) === 0) {
                continue;
            }
            if (in_array($p, $parts, true)) {
                continue;
            }
            $parts[] = $p;
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function extractShipmentSelfHref(array $detail): string
    {
        $links = $detail['_links'] ?? null;
        if (! is_array($links)) {
            return '';
        }
        $self = $links['self'] ?? null;
        if (! is_array($self)) {
            return '';
        }
        $href = $self['href'] ?? '';

        return is_string($href) ? trim($href) : '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function simplifyServicePointRow(array $row): array
    {
        $id = isset($row['id']) ? (int) $row['id'] : 0;
        $name = self::servicePointPickString($row, ['name']);
        $extId = self::servicePointPickString($row, ['extId', 'ext_id']);
        $rawAddress = self::servicePointPickString($row, ['rawAddress', 'raw_address']);
        $rawPhone = self::servicePointPickString($row, ['rawPhone', 'raw_phone']);
        $rawTimetable = self::servicePointPickString($row, ['rawTimetable', 'raw_timetable']);
        $rawDescription = self::servicePointPickString($row, ['rawDescription', 'raw_description']);
        $type = self::servicePointPickString($row, ['type']);
        $state = self::servicePointPickString($row, ['state']);
        $geo = self::servicePointPickString($row, ['geo']);
        $hours = self::servicePointWorkingHoursFromRawDescription($rawDescription);

        return [
            'id' => $id,
            'name' => $name,
            'ext_id' => $extId,
            'address' => $rawAddress,
            'raw_address' => $rawAddress,
            'raw_phone' => $rawPhone,
            'raw_timetable' => $rawTimetable,
            'raw_description' => $rawDescription,
            'type' => $type,
            'state' => $state,
            'geo' => $geo,
            'working_hours_summary' => $hours,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function servicePointPickString(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && is_string($row[$k])) {
                $t = trim($row[$k]);
                if ($t !== '') {
                    return $t;
                }
            }
        }

        return '';
    }

    private static function servicePointWorkingHoursFromRawDescription(string $raw): string
    {
        if ($raw === '' || strlen($raw) > 65536) {
            return '';
        }
        $schedule = json_decode($raw, true);
        if (! is_array($schedule) || $schedule === []) {
            return '';
        }
        $today = null;
        foreach ($schedule as $s) {
            if (is_array($s) && ! empty($s['standardSchedule'])) {
                $today = $s;
                break;
            }
        }
        if ($today === null && isset($schedule[0]) && is_array($schedule[0])) {
            $today = $schedule[0];
        }
        if (! is_array($today)) {
            return '';
        }
        $from = isset($today['workingTimeFrom']) && is_string($today['workingTimeFrom']) ? trim($today['workingTimeFrom']) : '';
        $to = isset($today['workingTimeTo']) && is_string($today['workingTimeTo']) ? trim($today['workingTimeTo']) : '';
        if ($from === '' && $to === '') {
            return '';
        }

        return trim($from . ' – ' . $to);
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row from {@see simplifyPlaceRow()}
     */
    private function placeMeasuresNeedUiDefaults(array $row): bool
    {
        $w = isset($row['weight']) ? (float) $row['weight'] : 0.0;
        $dx = isset($row['dim_x']) ? (float) $row['dim_x'] : 0.0;
        $dy = isset($row['dim_y']) ? (float) $row['dim_y'] : 0.0;
        $dz = isset($row['dim_z']) ? (float) $row['dim_z'] : 0.0;

        return $w <= 0.0 && $dx <= 0.0 && $dy <= 0.0 && $dz <= 0.0;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function aggregateLabelMeasuresFromShipmentPlaces(int $shipmentId): ?array
    {
        $raw = $this->apiClient->fetchPlacesForDeliveryRequest($shipmentId);
        if ($raw === []) {
            return null;
        }

        $totalWeight = 0.0;
        $maxDx = 0.0;
        $maxDy = 0.0;
        $maxDz = 0.0;

        foreach ($raw as $p) {
            if (! is_array($p)) {
                continue;
            }
            $row = $this->simplifyPlaceRow($p);
            $totalWeight += (float) ($row['weight'] ?? 0.0);
            $maxDx = max($maxDx, (float) ($row['dim_x'] ?? 0.0));
            $maxDy = max($maxDy, (float) ($row['dim_y'] ?? 0.0));
            $maxDz = max($maxDz, (float) ($row['dim_z'] ?? 0.0));
        }

        return [
            max(1, (int) round($totalWeight)),
            max(1, (int) round($maxDx)),
            max(1, (int) round($maxDy)),
            max(1, (int) round($maxDz)),
        ];
    }

    private function simplifyPlaceRow(array $p): array
    {
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        $dims = $p['dimensions'] ?? [];
        $dx = is_array($dims) && isset($dims['x']) ? (float) $dims['x'] : 0.0;
        $dy = is_array($dims) && isset($dims['y']) ? (float) $dims['y'] : 0.0;
        $dz = is_array($dims) && isset($dims['z']) ? (float) $dims['z'] : 0.0;

        return [
            'id' => $id,
            'priority' => isset($p['priority']) && is_numeric($p['priority']) ? (int) $p['priority'] : 0,
            'weight' => isset($p['weight']) && is_numeric($p['weight']) ? (float) $p['weight'] : 0.0,
            'dim_x' => $dx,
            'dim_y' => $dy,
            'dim_z' => $dz,
            'items_count' => self::placeItemsCount($p),
        ];
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function placeItemsCount(array $p): int
    {
        if (isset($p['itemsCount']) && is_numeric($p['itemsCount'])) {
            return (int) $p['itemsCount'];
        }
        if (isset($p['items_count']) && is_numeric($p['items_count'])) {
            return (int) $p['items_count'];
        }

        return 0;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function parseLabelMeasurementsFromPost(WC_Order $order): array
    {
        $weightRaw = WooOrderWeights::contentsWeightTotal($order);
        $weightUnit = (string) get_option('woocommerce_weight_unit', 'kg');
        $defaultGrams = max(1, (int) round(WooOrderWeights::toGrams($weightRaw, $weightUnit)));

        $wg = isset($_POST['weight_grams']) ? (float) wp_unslash($_POST['weight_grams']) : (float) $defaultGrams;
        $weightGrams = max(1, (int) round($wg));

        $dx = isset($_POST['dim_x']) ? (float) wp_unslash($_POST['dim_x']) : 100.0;
        $dy = isset($_POST['dim_y']) ? (float) wp_unslash($_POST['dim_y']) : 100.0;
        $dz = isset($_POST['dim_z']) ? (float) wp_unslash($_POST['dim_z']) : 100.0;
        $dimX = max(1, (int) round($dx));
        $dimY = max(1, (int) round($dy));
        $dimZ = max(1, (int) round($dz));

        return [$weightGrams, $dimX, $dimY, $dimZ];
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

}
