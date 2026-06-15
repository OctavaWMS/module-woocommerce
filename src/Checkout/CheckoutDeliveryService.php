<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\PluginLog;
use OctavaWMS\WooCommerce\UiBranding;

use function is_array;
use function is_object;
use function is_string;

final class CheckoutDeliveryService
{
    public const NONCE_ACTION = 'octavawms_checkout_delivery';
    private const INITIAL_SERVICE_POINTS_LIMIT = 5;

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
        $isCheckout = function_exists('is_checkout') && is_checkout();
        $isCart = function_exists('is_cart') && is_cart();
        if (! $isCheckout && ! $isCart) {
            return;
        }
        if ($isCheckout && function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }

        $pluginFile = defined('OCTAVAWMS_PLUGIN_FILE') ? OCTAVAWMS_PLUGIN_FILE : dirname(__DIR__, 2) . '/octavawms-woocommerce.php';
        $pluginDir = dirname(__DIR__, 2);
        $helperScript = 'assets/js/checkout-delivery-helpers.js';
        $script = 'assets/js/checkout-delivery.js';
        $style = 'assets/css/checkout-delivery.css';
        $helperVersion = is_readable($pluginDir . '/' . $helperScript) ? (string) filemtime($pluginDir . '/' . $helperScript) : '1.0.0';
        $scriptVersion = is_readable($pluginDir . '/' . $script) ? (string) filemtime($pluginDir . '/' . $script) : '1.0.0';
        $styleVersion = is_readable($pluginDir . '/' . $style) ? (string) filemtime($pluginDir . '/' . $style) : '1.0.0';

        wp_enqueue_style(
            'octavawms-checkout-delivery',
            plugins_url($style, $pluginFile),
            [],
            $styleVersion
        );

        if (! $isCheckout) {
            return;
        }

        wp_enqueue_script(
            'octavawms-checkout-delivery-helpers',
            plugins_url($helperScript, $pluginFile),
            [],
            $helperVersion,
            true
        );

        wp_enqueue_script(
            'octavawms-checkout-delivery',
            plugins_url($script, $pluginFile),
            ['jquery', 'wc-checkout', 'octavawms-checkout-delivery-helpers'],
            $scriptVersion,
            true
        );
        wp_localize_script('octavawms-checkout-delivery', 'octavawmsCheckoutDelivery', [
            'ajaxUrl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'methodPrefix' => ShippingMethod::METHOD_ID,
            'showIzpratiAttribution' => $this->shouldShowIzpratiAttribution(),
            'strings' => [
                'pickupTitle' => __('Pickup point', 'octavawms'),
                'shippingTitle' => __('Shipping', 'octavawms'),
                'poweredByPrefix' => __('Работи с ', 'octavawms'),
                'poweredByMarkWord' => __('ИЗПРАТИ.БГ', 'octavawms'),
                'choosePickup' => __('Choose pickup point', 'octavawms'),
                'searchPickup' => __('Search pickup point', 'octavawms'),
                'loadingShipping' => __('Loading shipping options...', 'octavawms'),
                'loading' => __('Loading pickup points...', 'octavawms'),
                'noPoints' => __('No pickup points were found for this address.', 'octavawms'),
                'selected' => __('Selected', 'octavawms'),
                'nearMe' => __('Near me', 'octavawms'),
                'map' => __('Map', 'octavawms'),
                'list' => __('List', 'octavawms'),
                'locating' => __('Locating...', 'octavawms'),
                'mapLoading' => __('Loading map...', 'octavawms'),
                'locationUnavailable' => __('Location is not available in this browser.', 'octavawms'),
                'locationDenied' => __('Could not use your location. Showing pickup points for the selected city.', 'octavawms'),
                'pickupPoints' => __('pickup points', 'octavawms'),
            ],
        ]);
    }

    private function shouldShowIzpratiAttribution(): bool
    {
        if (UiBranding::currentBrandPack() !== UiBranding::PACK_IZPRATI) {
            return false;
        }

        return ! $this->hasRemoveBrandingPlan();
    }

    private function hasRemoveBrandingPlan(): bool
    {
        // TODO: Replace with backend plan endpoint once it is available.
        return false;
    }

    public function handleServicePoints(): void
    {
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');
        }

        $rateId = isset($_POST['rate_id']) ? sanitize_text_field((string) wp_unslash($_POST['rate_id'])) : '';
        $search = isset($_POST['search']) ? sanitize_text_field((string) wp_unslash($_POST['search'])) : '';
        $lat = $this->postedFloat('lat');
        $lng = $this->postedFloat('lng');
        $origin = $lat !== null && $lng !== null ? ['lat' => $lat, 'lng' => $lng] : null;
        $rate = $rateId !== '' ? CheckoutSession::rate($rateId) : null;
        if ($rate === null) {
            wp_send_json_error(['message' => __('Delivery option is no longer available. Please refresh checkout.', 'octavawms')], 404);

            return;
        }

        $requiresPoint = self::rateRequiresPickupPoint($rate);
        $items = [];
        if ($requiresPoint) {
            $items = $this->fetchServicePointsForRate($rate, $search, $origin);
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
        $displayLabel = $this->removeCarrierNamePrefix($label, $carrierName);
        [$displayText, $displayPrice] = $this->splitWooPriceAmount($displayLabel);
        $url = function_exists('esc_url') ? esc_url($logo) : htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return $label;
        }

        $alt = function_exists('esc_attr') ? esc_attr($carrierName) : htmlspecialchars($carrierName, ENT_QUOTES, 'UTF-8');
        $reviewLabel = $this->reviewShippingLabel($carrierName, $displayText, $displayPrice, $label);

        return sprintf(
            '<span class="octavawms-shipping-rate-main"><span class="octavawms-shipping-rate-logo"><img src="%s" alt="%s" loading="lazy" decoding="async"></span><span class="octavawms-shipping-rate-text">%s</span></span><span class="octavawms-shipping-rate-price">%s</span><span class="octavawms-shipping-rate-review-text">%s</span>',
            $url,
            $alt,
            $displayText,
            $displayPrice,
            $reviewLabel
        );
    }

    /**
     * @param array<string, mixed> $rate
     * @param array{lat: float, lng: float}|null $origin
     *
     * @return list<array<string, mixed>>
     */
    private function fetchServicePointsForRate(array $rate, string $search, ?array $origin = null): array
    {
        $context = CheckoutSession::context();
        $localityId = isset($context['localityId']) && is_numeric($context['localityId']) ? (int) $context['localityId'] : null;
        $deliveryServiceId = isset($rate['deliveryService']) && is_numeric($rate['deliveryService']) ? (int) $rate['deliveryService'] : null;
        $methodKind = (string) ($rate['methodKind'] ?? '');
        $type = $methodKind === 'locker' ? 'self_service_point' : ($methodKind === 'office' ? 'service_point' : '');
        if ($localityId === null || $localityId <= 0 || $deliveryServiceId === null || $deliveryServiceId <= 0 || $type === '') {
            return [];
        }

        $params = [
            'localityId' => $localityId,
            'deliveryServiceId' => $deliveryServiceId,
            'servicePointType' => $type,
            'search' => $search,
            'page' => 1,
            'perPage' => ($search === '' && $origin === null) ? self::INITIAL_SERVICE_POINTS_LIMIT : 100,
        ];
        if ($origin !== null) {
            $params['lat'] = $origin['lat'];
            $params['lng'] = $origin['lng'];
            $params['sort'] = 'distance';
            $params['browserGeolocationEnabled'] = true;
        }

        $result = $this->apiClient->fetchServicePoints($params);

        $items = [];
        foreach ($result['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            $type = $this->stringFromKeys($item, ['type', 'servicePointType']);
            $row = [
                'id' => $id,
                'name' => $this->stringFromKeys($item, ['name', 'title', 'displayName']),
                'address' => $this->servicePointAddress($item),
                'city' => $this->servicePointCity($item),
                'postcode' => $this->servicePointPostcode($item),
                'type' => $type,
                'typeLabel' => $this->servicePointTypeLabel($type),
            ];
            $coordinates = $this->servicePointCoordinates($item);
            if ($coordinates !== null) {
                $row['lat'] = $coordinates['lat'];
                $row['lng'] = $coordinates['lng'];
            }
            $items[] = $row;
        }
        $this->logServicePointsRequest($rate, $params, $result, $items);

        return $items;
    }

    /**
     * @param array<string, mixed> $rate
     * @param array<string, mixed> $params
     * @param array<string, mixed> $result
     * @param list<array<string, mixed>> $items
     */
    private function logServicePointsRequest(array $rate, array $params, array $result, array $items): void
    {
        $context = CheckoutSession::context();
        if (($context['debug'] ?? false) !== true) {
            return;
        }

        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        PluginLog::log('debug', 'checkout_service_points', [
            'rate' => [
                'deliveryService' => $rate['deliveryService'] ?? null,
                'rate' => $rate['rate'] ?? null,
                'methodKind' => $rate['methodKind'] ?? null,
                'title' => $rate['title'] ?? null,
            ],
            'params' => $params,
            'item_count' => count($items),
            'total_pages' => $result['total_pages'] ?? null,
            'request' => is_array($result['request'] ?? null) ? $result['request'] : null,
            'response' => [
                'http_status' => $response['http_status'] ?? null,
                'body_note' => 'Full response JSON omitted; see items preview.',
            ],
            'items' => array_slice(array_map(
                static fn (array $item): array => [
                    'id' => $item['id'] ?? null,
                    'name' => $item['name'] ?? '',
                    'address' => $item['address'] ?? '',
                    'city' => $item['city'] ?? '',
                    'postcode' => $item['postcode'] ?? '',
                    'type' => $item['type'] ?? '',
                ],
                $items
            ), 0, self::INITIAL_SERVICE_POINTS_LIMIT),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function servicePointAddress(array $item): string
    {
        $direct = $this->stringFromKeys($item, ['rawAddress', 'displayAddress', 'fullAddress']);
        if ($direct !== '') {
            return $this->normalizeServicePointAddress($direct);
        }

        $address = $item['address'] ?? null;
        if (is_string($address) && trim($address) !== '') {
            return $this->normalizeServicePointAddress($address);
        }
        if (is_array($address)) {
            $line = $this->addressLineFromArray($address);
            if ($line !== '') {
                return $this->normalizeServicePointAddress($line);
            }
        }

        $rawAddress = $this->nestedArrayFromKeys($item, ['rawAddress', 'displayAddress', 'fullAddress']);
        if ($rawAddress !== null) {
            $line = $this->addressLineFromArray($rawAddress);
            if ($line !== '') {
                return $this->normalizeServicePointAddress($line);
            }
        }

        $raw = $this->nestedArrayFromKeys($item, ['raw']);
        if ($raw !== null) {
            $rawAddress = $this->nestedArrayFromKeys($raw, ['address', 'rawAddress', 'displayAddress']);
            if ($rawAddress !== null) {
                $line = $this->addressLineFromArray($rawAddress);
                if ($line !== '') {
                    return $this->normalizeServicePointAddress($line);
                }
            }
        }

        return $this->normalizeServicePointAddress($this->stringFromKeys($item, [
            'addressLine',
            'address1',
            'line1',
            'street',
            'streetName',
            'fullAddress',
            'displayAddress',
            'rawAddress',
        ]));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function servicePointCity(array $item): string
    {
        $city = $this->stringFromKeys($item, ['city', 'locality', 'localityName', 'settlement']);
        if ($city !== '') {
            return $city;
        }

        $embeddedLocality = $this->embeddedLocality($item);
        if ($embeddedLocality !== null) {
            $city = $this->stringFromKeys($embeddedLocality, ['name', 'localityName', 'city']);
            if ($city !== '') {
                return $city;
            }
        }

        return $this->stringFromNestedAddress($item, ['city', 'locality', 'localityName', 'settlement']);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function servicePointPostcode(array $item): string
    {
        $postcode = $this->stringFromKeys($item, ['postcode', 'postCode', 'postalCode', 'zip']);
        if ($postcode !== '') {
            return $postcode;
        }

        $embeddedLocality = $this->embeddedLocality($item);
        if ($embeddedLocality !== null) {
            $postcode = $this->stringFromKeys($embeddedLocality, ['postcode', 'postCode', 'postalCode', 'zip']);
            if ($postcode !== '') {
                return $postcode;
            }
        }

        return $this->stringFromNestedAddress($item, ['postcode', 'postCode', 'postalCode', 'zip']);
    }

    private function normalizeServicePointAddress(string $address): string
    {
        $address = trim(preg_replace('/\s+/u', ' ', $address) ?: $address);
        $address = preg_replace('/\bNo\.?\s*/u', '№ ', $address);

        return is_string($address) ? trim($address) : '';
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>|null
     */
    private function embeddedLocality(array $item): ?array
    {
        $embedded = $this->nestedArrayFromKeys($item, ['_embedded']);
        if ($embedded === null) {
            return null;
        }

        return $this->nestedArrayFromKeys($embedded, ['locality']);
    }

    private function servicePointTypeLabel(string $type): string
    {
        return match ($type) {
            'self_service_point' => __('Locker', 'octavawms'),
            'service_point' => __('Office', 'octavawms'),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     */
    private function stringFromNestedAddress(array $item, array $keys): string
    {
        $address = $this->nestedArrayFromKeys($item, ['address']);
        if ($address !== null) {
            $value = $this->stringFromKeys($address, $keys);
            if ($value !== '') {
                return $value;
            }
        }

        $raw = $this->nestedArrayFromKeys($item, ['raw']);
        if ($raw === null) {
            return '';
        }

        $rawAddress = $this->nestedArrayFromKeys($raw, ['address', 'rawAddress', 'displayAddress']);
        if ($rawAddress !== null) {
            return $this->stringFromKeys($rawAddress, $keys);
        }

        return $this->stringFromKeys($raw, $keys);
    }

    /**
     * @param array<string, mixed> $address
     */
    private function addressLineFromArray(array $address): string
    {
        $line = $this->stringFromKeys($address, [
            'full',
            'text',
            'formatted',
            'display',
            'displayAddress',
            'raw',
            'address',
            'addressLine',
            'address1',
            'line1',
        ]);
        if ($line !== '') {
            return $line;
        }

        return $this->joinUnique([
            $this->stringFromKeys($address, ['street', 'streetName']),
            $this->stringFromKeys($address, ['streetNo', 'streetNumber', 'number']),
        ], ' ');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function stringFromKeys(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if ((is_int($value) || is_float($value)) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     *
     * @return array<string, mixed>|null
     */
    private function nestedArrayFromKeys(array $data, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * @param list<string> $parts
     */
    private function joinUnique(array $parts, string $separator): string
    {
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || in_array($part, $result, true)) {
                continue;
            }
            $result[] = $part;
        }

        return implode($separator, $result);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{lat: float, lng: float}|null
     */
    private function servicePointCoordinates(array $item): ?array
    {
        $lat = $this->numericFromKeys($item, ['lat', 'latitude']);
        $lng = $this->numericFromKeys($item, ['lng', 'lon', 'longitude']);
        if ($lat !== null && $lng !== null) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        $geo = $item['geo'] ?? null;
        if (is_array($geo)) {
            $lat = $this->numericFromKeys($geo, ['lat', 'latitude']);
            $lng = $this->numericFromKeys($geo, ['lng', 'lon', 'longitude']);
            if ($lat !== null && $lng !== null) {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }
        if (! is_string($geo)) {
            return null;
        }

        if (preg_match('/POINT\s*\(\s*([-+]?\d+(?:\.\d+)?)\s+([-+]?\d+(?:\.\d+)?)\s*\)/i', $geo, $match) === 1) {
            return ['lat' => (float) $match[2], 'lng' => (float) $match[1]];
        }

        $parts = array_map('trim', explode(',', $geo));
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return ['lat' => (float) $parts[1], 'lng' => (float) $parts[0]];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function numericFromKeys(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        return null;
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

    private function removeCarrierNamePrefix(string $label, string $carrierName): string
    {
        if ($carrierName === '') {
            return $label;
        }

        $clean = preg_replace('/^\s*' . preg_quote($carrierName, '/') . '\s*[-–—]\s*/iu', '', $label, 1);

        return is_string($clean) && $clean !== '' ? $clean : $label;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitWooPriceAmount(string $label): array
    {
        if (preg_match('/^(.*?)(<span\b[^>]*woocommerce-Price-amount\b.*)$/su', $label, $match) !== 1) {
            return [trim($label), ''];
        }

        return [trim((string) $match[1]), trim((string) $match[2])];
    }

    private function reviewShippingLabel(string $carrierName, string $displayText, string $displayPrice, string $fallback): string
    {
        $carrier = trim($carrierName) !== '' ? mb_strtoupper(trim($carrierName), 'UTF-8') : '';
        $type = trim($displayText);
        if ($carrier === '' || $type === '') {
            return $fallback;
        }

        return trim($carrier . ' ' . $type . ($displayPrice !== '' ? ' ' . $displayPrice : ''));
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

    private function postedFloat(string $key): ?float
    {
        if (! isset($_POST[$key])) {
            return null;
        }

        $value = wp_unslash($_POST[$key]);

        return is_numeric($value) ? (float) $value : null;
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
