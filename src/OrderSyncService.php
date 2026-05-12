<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use WC_Order;

/**
 * Auto-imports WooCommerce orders to OctavaWMS on create/update (when enabled in integration settings).
 *
 * Outbound imports are limited to once per order per transient window on the server so bursts of WooCommerce hooks
 * (and parallel PHP workers) do not overrun API rate limits. Filter {@code octavawms_order_import_throttle_seconds}
 * (seconds, default 30; {@code 0} disables cross-request throttle). Filter {@code octavawms_register_order_sync_hooks}
 * with {@code false} to unregister all Woo auto-sync hooks.
 */
final class OrderSyncService
{
    /** Transient key: last outbound import attempt for one order across PHP requests (rate-limit friendly). */
    private const IMPORT_THROTTLE_TRANSIENT_PREFIX = 'octavawms_ord_import_';

    /** @var array<int, true> Order IDs already imported this HTTP request (any hook). */
    private array $importedThisRequest = [];

    public function __construct(
        private readonly BackendApiClient $apiClient
    ) {
    }

    public function register(): void
    {
        if (! apply_filters('octavawms_register_order_sync_hooks', true)) {
            return;
        }

        add_action('woocommerce_checkout_order_processed', [$this, 'onCheckoutOrderProcessed'], 20, 3);
        add_action('woocommerce_new_order', [$this, 'onNewOrder'], 20, 2);
        add_action('woocommerce_update_order', [$this, 'onOrderUpdate'], 20, 1);
        add_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 20, 4);
    }

    /**
     * @param int|\WC_Order $order_id
     * @param array<string, mixed> $posted_data
     */
    public function onCheckoutOrderProcessed($order_id, array $posted_data, $order = null): void
    {
        unset($posted_data);
        if (! Options::isNewOrderSyncEnabled()) {
            return;
        }
        $id = $this->resolveOrderId($order_id, $order);
        if ($id > 0) {
            $this->syncNewOrder($id);
        }
    }

    /**
     * @param int|\WC_Order $order_id
     */
    public function onNewOrder($order_id, $order = null): void
    {
        if (! Options::isNewOrderSyncEnabled()) {
            return;
        }
        $id = $this->resolveOrderId($order_id, $order);
        if ($id > 0) {
            $this->syncNewOrder($id);
        }
    }

    /**
     * @param int|\WC_Order $order_id
     */
    public function onOrderUpdate($order_id): void
    {
        if (! Options::isOrderUpdateSyncEnabled()) {
            return;
        }
        $id = $this->resolveOrderId($order_id, null);
        if ($id > 0) {
            $this->syncOrderUpdate($id);
        }
    }

    /**
     * @param int|\WC_Order $order_id
     */
    public function onOrderStatusChanged($order_id, string $from, string $to, $order = null): void
    {
        unset($from, $to);
        if (! Options::isOrderUpdateSyncEnabled()) {
            return;
        }
        $id = $this->resolveOrderId($order_id, $order);
        if ($id > 0) {
            $this->syncOrderUpdate($id);
        }
    }

    /**
     * @param int|\WC_Order|null $first
     * @param \WC_Order|null $second
     */
    private function resolveOrderId($first, $second): int
    {
        if ($first instanceof WC_Order) {
            return (int) $first->get_id();
        }
        if (is_numeric($first) && (int) $first > 0) {
            return (int) $first;
        }
        if ($second instanceof WC_Order) {
            return (int) $second->get_id();
        }

        return 0;
    }

    private function syncNewOrder(int $orderId): void
    {
        if (isset($this->importedThisRequest[$orderId])) {
            return;
        }
        $this->importedThisRequest[$orderId] = true;
        $this->doImport($orderId);
    }

    private function syncOrderUpdate(int $orderId): void
    {
        if (isset($this->importedThisRequest[$orderId])) {
            return;
        }

        $this->importedThisRequest[$orderId] = true;

        $this->doImport($orderId);
    }

    private function doImport(int $orderId): void
    {
        if (! function_exists('wc_get_order')) {
            return;
        }

        if (Options::getSourceId() <= 0 || Options::getApiKey() === '') {
            return;
        }

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }

        $seconds = max(0, (int) apply_filters('octavawms_order_import_throttle_seconds', 30));
        if ($seconds > 0) {
            $throttleKey = self::IMPORT_THROTTLE_TRANSIENT_PREFIX . $orderId;
            if (get_transient($throttleKey) !== false) {
                return;
            }
            set_transient($throttleKey, '1', $seconds);
        }

        $extId = WooOrderExtId::importFilterExtId($order);
        $result = $this->apiClient->importOrder($extId, Options::getSourceId());

        if (! $result['ok']) {
            PluginLog::log('error', 'order_auto_sync', [
                'order_id' => $orderId,
                'ext_id' => $extId,
                'message' => (string) ($result['message'] ?? 'import failed'),
            ]);

            return;
        }

        $data = $result['data'] ?? null;
        if (is_array($data)) {
            $entity = $this->apiClient->extractFirstOrderFromCollectionJson($data);
            $this->maybePersistCanonicalExtIdFromEntity($order, $entity);
        }
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
}
