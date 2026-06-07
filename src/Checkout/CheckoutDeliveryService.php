<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

use OctavaWMS\WooCommerce\Api\BackendApiClient;

use function is_array;
use function is_object;
use function is_string;

final class CheckoutDeliveryService
{
    public const NONCE_ACTION = 'octavawms_checkout_delivery';

    public function __construct(private readonly BackendApiClient $apiClient)
    {
    }

    public function register(): void
    {
        add_filter('woocommerce_shipping_methods', [$this, 'registerShippingMethod']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_octavawms_checkout_service_points', [$this, 'handleServicePoints']);
        add_action('wp_ajax_nopriv_octavawms_checkout_service_points', [$this, 'handleServicePoints']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateCheckout'], 10, 2);
        add_action('woocommerce_checkout_create_order_shipping_item', [$this, 'persistShippingItemMeta'], 10, 4);
        add_action('woocommerce_checkout_create_order', [$this, 'persistOrderMeta'], 20, 2);
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'formatShippingMethodFullLabel'], 10, 2);
    }

    /**
     * @param array<string, mixed> $methods
     *
     * @return array<string, mixed>
     */
    public function registerShippingMethod(array $methods): array
    {
        $methods[ShippingMethod::METHOD_ID] = ShippingMethod::class;

        return $methods;
    }

    public function enqueueAssets(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }

        $pluginFile = defined('OCTAVAWMS_PLUGIN_FILE') ? OCTAVAWMS_PLUGIN_FILE : dirname(__DIR__, 2) . '/octavawms-woocommerce.php';
        $pluginDir = dirname(__DIR__, 2);
        $script = 'assets/js/checkout-delivery.js';
        $style = 'assets/css/checkout-delivery.css';
        $scriptVersion = is_readable($pluginDir . '/' . $script) ? (string) filemtime($pluginDir . '/' . $script) : '1.0.0';
        $styleVersion = is_readable($pluginDir . '/' . $style) ? (string) filemtime($pluginDir . '/' . $style) : '1.0.0';

        wp_enqueue_style(
            'octavawms-checkout-delivery',
            plugins_url($style, $pluginFile),
            [],
            $styleVersion
        );
        wp_enqueue_script(
            'octavawms-checkout-delivery',
            plugins_url($script, $pluginFile),
            ['jquery', 'wc-checkout'],
            $scriptVersion,
            true
        );
        wp_localize_script('octavawms-checkout-delivery', 'octavawmsCheckoutDelivery', [
            'ajaxUrl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'methodPrefix' => ShippingMethod::METHOD_ID,
            'strings' => [
                'pickupTitle' => __('Pickup point', 'octavawms'),
                'choosePickup' => __('Choose pickup point', 'octavawms'),
                'searchPickup' => __('Search pickup point', 'octavawms'),
                'loadingShipping' => __('Loading shipping options...', 'octavawms'),
                'loading' => __('Loading pickup points...', 'octavawms'),
                'noPoints' => __('No pickup points were found for this address.', 'octavawms'),
                'selected' => __('Selected', 'octavawms'),
            ],
        ]);
    }

    public function handleServicePoints(): void
    {
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');
        }

        $rateId = isset($_POST['rate_id']) ? sanitize_text_field((string) wp_unslash($_POST['rate_id'])) : '';
        $search = isset($_POST['search']) ? sanitize_text_field((string) wp_unslash($_POST['search'])) : '';
        $rate = $rateId !== '' ? CheckoutSession::rate($rateId) : null;
        if ($rate === null) {
            wp_send_json_error(['message' => __('Delivery option is no longer available. Please refresh checkout.', 'octavawms')], 404);

            return;
        }

        $requiresPoint = self::rateRequiresPickupPoint($rate);
        $items = [];
        if ($requiresPoint) {
            $items = is_array($rate['servicePoints'] ?? null) ? $rate['servicePoints'] : [];
            if ($search !== '' || $items === []) {
                $items = $this->fetchServicePointsForRate($rate, $search);
            } elseif ($items !== []) {
                $items = array_slice($items, 0, 100);
            }
        }

        wp_send_json_success([
            'requiresPoint' => $requiresPoint,
            'methodKind' => (string) ($rate['methodKind'] ?? 'address'),
            'title' => (string) ($rate['title'] ?? ''),
            'carrierName' => (string) ($rate['carrierName'] ?? ''),
            'items' => $items,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validateCheckout(array $data, mixed $errors): void
    {
        $rateId = $this->selectedRateId($data);
        if (! self::isOrderadminRateId($rateId)) {
            return;
        }
        $rate = CheckoutSession::rate($rateId);
        if ($rate === null) {
            $this->addValidationError($errors, __('Please choose a delivery option again.', 'octavawms'));

            return;
        }
        if (! self::rateRequiresPickupPoint($rate)) {
            return;
        }

        $pointId = $this->postedInt('octavawms_service_point_id');
        if ($pointId <= 0) {
            $this->addValidationError($errors, __('Choose a pickup point before placing the order.', 'octavawms'));
        }
    }

    /**
     * @param mixed $item WC_Order_Item_Shipping in WooCommerce
     * @param mixed $packageKey
     * @param mixed $package
     * @param mixed $order
     */
    public function persistShippingItemMeta(mixed $item, mixed $packageKey, mixed $package, mixed $order): void
    {
        unset($packageKey, $package, $order);
        $rateId = $this->selectedRateId($_POST);
        $selection = $this->buildSelection($rateId);
        if ($selection === null || ! is_object($item) || ! method_exists($item, 'add_meta_data')) {
            return;
        }

        $item->add_meta_data('deliveryService', $selection['deliveryService'], true);
        $item->add_meta_data('rate', $selection['rate'], true);
        $item->add_meta_data('servicePoint', $selection['servicePoint'], true);
        CheckoutSession::storeSelection($selection);
    }

    /**
     * @param mixed $order WC_Order in WooCommerce
     * @param array<string, mixed> $data
     */
    public function persistOrderMeta(mixed $order, array $data): void
    {
        unset($data);
        $rateId = $this->selectedRateId($_POST);
        $selection = $this->buildSelection($rateId) ?? CheckoutSession::selection();
        if ($selection === [] || ! is_object($order) || ! method_exists($order, 'update_meta_data')) {
            return;
        }

        $order->update_meta_data('_octavawms_delivery_rate_id', (string) ($selection['rateId'] ?? ''));
        $order->update_meta_data('_octavawms_delivery_service', $selection['deliveryService'] ?? null);
        $order->update_meta_data('_octavawms_delivery_rate', $selection['rate'] ?? null);
        $order->update_meta_data('_octavawms_service_point', $selection['servicePoint'] ?? null);
    }

    /**
     * @param array<string, mixed> $rate
     */
    public static function rateRequiresPickupPoint(array $rate): bool
    {
        return in_array((string) ($rate['methodKind'] ?? 'address'), ['office', 'locker', 'office_locker'], true);
    }

    public static function isOrderadminRateId(string $rateId): bool
    {
        return $rateId === ShippingMethod::METHOD_ID || str_starts_with($rateId, ShippingMethod::METHOD_ID . ':');
    }

    public function formatShippingMethodFullLabel(string $label, mixed $method): string
    {
        $rateId = $this->shippingRateId($method);
        if (! self::isOrderadminRateId($rateId)) {
            return $label;
        }

        $label = $this->removeWooPriceSeparatorColon($label);
        $rate = CheckoutSession::rate($rateId);
        $logo = is_array($rate) && isset($rate['carrierLogo']) && is_string($rate['carrierLogo']) ? trim($rate['carrierLogo']) : '';
        if ($logo === '') {
            return $label;
        }

        $carrierName = is_array($rate) && isset($rate['carrierName']) && is_string($rate['carrierName']) ? trim($rate['carrierName']) : '';
        $url = function_exists('esc_url') ? esc_url($logo) : htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return $label;
        }

        $alt = function_exists('esc_attr') ? esc_attr($carrierName) : htmlspecialchars($carrierName, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<span class="octavawms-shipping-rate-logo"><img src="%s" alt="%s" loading="lazy" decoding="async"></span>%s',
            $url,
            $alt,
            $label
        );
    }

    /**
     * @param array<string, mixed> $rate
     *
     * @return list<array<string, mixed>>
     */
    private function fetchServicePointsForRate(array $rate, string $search): array
    {
        $context = CheckoutSession::context();
        $localityId = isset($context['localityId']) && is_numeric($context['localityId']) ? (int) $context['localityId'] : null;
        $deliveryServiceId = isset($rate['deliveryService']) && is_numeric($rate['deliveryService']) ? (int) $rate['deliveryService'] : null;
        $methodKind = (string) ($rate['methodKind'] ?? '');
        $type = $methodKind === 'locker' ? 'self_service_point' : ($methodKind === 'office' ? 'service_point' : '');
        if ($localityId === null || $localityId <= 0 || $deliveryServiceId === null || $deliveryServiceId <= 0 || $type === '') {
            return [];
        }

        $result = $this->apiClient->fetchServicePoints([
            'localityId' => $localityId,
            'deliveryServiceId' => $deliveryServiceId,
            'servicePointType' => $type,
            'search' => $search,
            'page' => 1,
            'perPage' => 100,
        ]);

        $items = [];
        foreach ($result['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'name' => (string) ($item['name'] ?? ''),
                'address' => (string) ($item['address'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
            ];
        }

        return $items;
    }

    private function shippingRateId(mixed $method): string
    {
        if (is_object($method) && method_exists($method, 'get_id')) {
            $id = $method->get_id();

            return is_string($id) ? $id : '';
        }
        if (is_object($method) && isset($method->id) && is_string($method->id)) {
            return $method->id;
        }
        if (is_array($method) && isset($method['id']) && is_string($method['id'])) {
            return $method['id'];
        }

        return '';
    }

    private function removeWooPriceSeparatorColon(string $label): string
    {
        $clean = preg_replace('/:\s*(<span\b[^>]*woocommerce-Price-amount[^>]*>)/u', ' $1', $label, 1);
        if (is_string($clean) && $clean !== $label) {
            return $clean;
        }

        return (string) preg_replace('/:\s*$/u', '', $label);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function selectedRateId(array $data): string
    {
        $shippingMethod = $data['shipping_method'] ?? ($_POST['shipping_method'] ?? null);
        if (is_array($shippingMethod)) {
            foreach ($shippingMethod as $value) {
                if (is_string($value) && self::isOrderadminRateId($value)) {
                    return $value;
                }
            }
        }
        if (is_string($shippingMethod) && self::isOrderadminRateId($shippingMethod)) {
            return $shippingMethod;
        }

        return isset($_POST['octavawms_delivery_rate_id']) && is_string($_POST['octavawms_delivery_rate_id'])
            ? sanitize_text_field((string) wp_unslash($_POST['octavawms_delivery_rate_id']))
            : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSelection(string $rateId): ?array
    {
        if (! self::isOrderadminRateId($rateId)) {
            return null;
        }
        $rate = CheckoutSession::rate($rateId);
        if ($rate === null) {
            return null;
        }

        $servicePoint = null;
        if (self::rateRequiresPickupPoint($rate)) {
            $pointId = $this->postedInt('octavawms_service_point_id');
            $servicePoint = $pointId > 0 ? $pointId : null;
        }

        return [
            'rateId' => $rateId,
            'deliveryService' => (int) ($rate['deliveryService'] ?? 0),
            'rate' => isset($rate['rate']) && is_numeric($rate['rate']) ? (int) $rate['rate'] : null,
            'servicePoint' => $servicePoint,
            'methodKind' => (string) ($rate['methodKind'] ?? 'address'),
        ];
    }

    private function postedInt(string $key): int
    {
        if (! isset($_POST[$key])) {
            return 0;
        }

        return (int) absint(wp_unslash($_POST[$key]));
    }

    private function addValidationError(mixed $errors, string $message): void
    {
        if (is_object($errors) && method_exists($errors, 'add')) {
            $errors->add('octavawms_delivery', $message);

            return;
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
        }
    }
}
