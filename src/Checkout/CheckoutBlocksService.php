<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

use RuntimeException;

use function is_array;
use function is_object;
use function is_string;

final class CheckoutBlocksService
{
    private const UPDATE_NAMESPACE = 'octavawms-delivery';

    public function __construct(private readonly CheckoutDeliveryService $deliveryService)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_blocks_loaded', [$this, 'registerStoreApiUpdate']);
        add_action('woocommerce_blocks_enqueue_checkout_block_scripts_after', [$this, 'enqueueCheckoutScript']);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'persistOrderSelection'], 10, 2);
    }

    public function registerStoreApiUpdate(): void
    {
        if (! function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }

        woocommerce_store_api_register_update_callback([
            'namespace' => self::UPDATE_NAMESPACE,
            'callback' => [$this, 'storeSelection'],
        ]);
    }

    public function enqueueCheckoutScript(): void
    {
        $pluginFile = defined('OCTAVAWMS_PLUGIN_FILE')
            ? OCTAVAWMS_PLUGIN_FILE
            : dirname(__DIR__, 2) . '/octavawms-woocommerce.php';
        $pluginDir = dirname(__DIR__, 2);
        $script = 'assets/js/checkout-delivery-blocks.js';
        $version = is_readable($pluginDir . '/' . $script) ? (string) filemtime($pluginDir . '/' . $script) : '1.0.0';

        wp_enqueue_script(
            'octavawms-checkout-delivery-blocks',
            plugins_url($script, $pluginFile),
            ['wp-data', 'wp-element', 'wp-i18n', 'wp-plugins', 'wc-blocks-checkout'],
            $version,
            true
        );
        wp_localize_script('octavawms-checkout-delivery-blocks', 'octavawmsCheckoutDeliveryBlocks', [
            'ajaxUrl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            'nonce' => wp_create_nonce(CheckoutDeliveryService::NONCE_ACTION),
            'methodPrefix' => ShippingMethod::METHOD_ID,
            'updateNamespace' => self::UPDATE_NAMESPACE,
            'strings' => [
                'pickupTitle' => __('Pickup point', 'izprati-bg-shipping'),
                'choosePickup' => __('Choose pickup point', 'izprati-bg-shipping'),
                'loading' => __('Loading pickup points...', 'izprati-bg-shipping'),
                'noPoints' => __('No pickup points were found for this address.', 'izprati-bg-shipping'),
                'saving' => __('Saving pickup point...', 'izprati-bg-shipping'),
                'error' => __('Could not update the pickup point. Please try again.', 'izprati-bg-shipping'),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function storeSelection(array $data): void
    {
        $rateId = isset($data['rateId']) && is_string($data['rateId'])
            ? sanitize_text_field($data['rateId'])
            : '';
        $pointId = isset($data['servicePointId']) ? (int) absint($data['servicePointId']) : 0;
        $rate = CheckoutSession::rate($rateId);

        if (! CheckoutDeliveryService::isOrderadminRateId($rateId) || $rate === null) {
            CheckoutSession::storeSelection([]);

            return;
        }

        $requiresPoint = CheckoutDeliveryService::rateRequiresPickupPoint($rate);
        CheckoutSession::storeSelection([
            'rateId' => $rateId,
            'deliveryService' => (int) ($rate['deliveryService'] ?? 0),
            'rate' => isset($rate['rate']) && is_numeric($rate['rate']) ? (int) $rate['rate'] : null,
            'servicePoint' => $requiresPoint && $pointId > 0 ? $pointId : null,
            'methodKind' => (string) ($rate['methodKind'] ?? 'address'),
        ]);
    }

    public function persistOrderSelection(mixed $order, mixed $request): void
    {
        unset($request);
        if (! is_object($order) || ! method_exists($order, 'update_meta_data')) {
            return;
        }

        $rateId = $this->selectedRateId();
        if (! CheckoutDeliveryService::isOrderadminRateId($rateId)) {
            return;
        }

        $rate = CheckoutSession::rate($rateId);
        $selection = CheckoutSession::selection();
        if ($rate === null || ($selection['rateId'] ?? '') !== $rateId) {
            $this->rejectCheckout(__('Please choose a delivery option again.', 'izprati-bg-shipping'));
        }
        if (CheckoutDeliveryService::rateRequiresPickupPoint($rate)) {
            $pointId = isset($selection['servicePoint']) ? (int) $selection['servicePoint'] : 0;
            if ($pointId <= 0) {
                $this->rejectCheckout(__('Choose a pickup point before placing the order.', 'izprati-bg-shipping'));
            }
        }

        $order->update_meta_data('_octavawms_delivery_rate_id', $rateId);
        $order->update_meta_data('_octavawms_delivery_service', $selection['deliveryService'] ?? null);
        $order->update_meta_data('_octavawms_delivery_rate', $selection['rate'] ?? null);
        $order->update_meta_data('_octavawms_service_point', $selection['servicePoint'] ?? null);

        if (! method_exists($order, 'get_items')) {
            return;
        }
        $items = $order->get_items('shipping');
        if (! is_array($items) && ! ($items instanceof \Traversable)) {
            return;
        }
        foreach ($items as $item) {
            if (! is_object($item) || ! method_exists($item, 'add_meta_data')) {
                continue;
            }
            if (
                method_exists($item, 'get_method_id')
                && ! CheckoutDeliveryService::isOrderadminRateId((string) $item->get_method_id())
            ) {
                continue;
            }
            $item->add_meta_data('deliveryService', $selection['deliveryService'] ?? null, true);
            $item->add_meta_data('rate', $selection['rate'] ?? null, true);
            $item->add_meta_data('servicePoint', $selection['servicePoint'] ?? null, true);
        }
    }

    private function selectedRateId(): string
    {
        if (! function_exists('WC')) {
            return (string) (CheckoutSession::selection()['rateId'] ?? '');
        }
        $wc = WC();
        if (! is_object($wc) || ! isset($wc->session) || ! is_object($wc->session) || ! method_exists($wc->session, 'get')) {
            return '';
        }
        $methods = $wc->session->get('chosen_shipping_methods', []);
        if (! is_array($methods)) {
            return '';
        }
        foreach ($methods as $method) {
            if (is_string($method) && CheckoutDeliveryService::isOrderadminRateId($method)) {
                return $method;
            }
        }

        return '';
    }

    private function rejectCheckout(string $message): never
    {
        $exception = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
        if (class_exists($exception)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the Store API exception serializes the message; this is not direct HTML output.
            throw new $exception('octavawms_delivery_selection_required', $message, 400);
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message is not direct HTML output.
        throw new RuntimeException($message);
    }
}
