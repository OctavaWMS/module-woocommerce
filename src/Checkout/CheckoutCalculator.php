<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\PluginLog;

use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_object;
use function is_string;

final class CheckoutCalculator
{
    private const MIN_PACKAGE_WEIGHT_GRAMS = 10.0;

    public function __construct(private readonly BackendApiClient $apiClient)
    {
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return list<array<string, mixed>>
     */
    public function calculatePackage(array $package): array
    {
        $payload = $this->buildPayload($package);
        if ($payload === null) {
            return [];
        }

        $result = $this->apiClient->request('POST', '/api/delivery-services/calculator', $payload);
        $logContext = $this->calculatorLogContext($package, $payload, $result);
        if (! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
            $this->logCalculator('warning', 'api_error', $logContext + [
                'message' => PluginLog::userMessageFromApiJson(
                    is_array($result['data'] ?? null) ? $result['data'] : null,
                    (string) ($result['raw'] ?? __('Calculator request failed.', 'octavawms'))
                ),
            ]);

            return [];
        }

        $rates = $this->normalizeRates($result['data']);
        $rawRateCount = is_array($result['data']['rates'] ?? null) ? count($result['data']['rates']) : 0;
        $this->logCalculator(
            $rates === [] ? 'warning' : 'info',
            $rates === [] ? 'no_rates' : 'ok',
            $logContext + [
                'raw_rate_count' => $rawRateCount,
                'normalized_rate_count' => count($rates),
            ]
        );

        return $rates;
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array<string, mixed>|null
     */
    public function buildPayload(array $package): ?array
    {
        $destination = is_array($package['destination'] ?? null) ? $package['destination'] : [];
        $diag = $this->packageDiagnostics($package, $destination);

        $sourceId = Options::getSourceId();
        if ($sourceId <= 0) {
            $this->logCalculator('warning', 'no_source_id', $diag + ['source_id' => $sourceId]);

            return null;
        }

        $source = $this->apiClient->getIntegrationSource($sourceId);
        $options = $this->extractDeliveryOptions($source ?? []);

        $sender = isset($options['sender']) && is_numeric($options['sender']) ? (int) $options['sender'] : 0;
        if ($sender <= 0) {
            $resolved = (new AutoSetSender($this->apiClient))->resolve($sourceId, $source, $sender);
            $sender = (int) ($resolved['sender_id'] ?? 0);
            if ($sender <= 0) {
                $logContext = $diag + [
                    'source_id' => $sourceId,
                    'sender' => 0,
                    'sender_count' => $resolved['sender_count'] ?? null,
                ];
                if (isset($resolved['message']) && is_string($resolved['message']) && $resolved['message'] !== '') {
                    $logContext['message'] = $resolved['message'];
                }
                if (is_array($resolved['diagnostics'] ?? null)) {
                    $logContext += $resolved['diagnostics'];
                }
                $this->logCalculator('warning', (string) ($resolved['outcome'] ?? 'no_sender'), $logContext);

                return null;
            }
            if (($resolved['outcome'] ?? '') === 'auto_set') {
                $this->logCalculator('info', 'auto_set_sender', $diag + [
                    'source_id' => $sourceId,
                    'sender' => $sender,
                    'sender_count' => 1,
                ]);
            }
        }

        $postcode = isset($destination['postcode']) && is_string($destination['postcode']) ? trim($destination['postcode']) : '';
        $country = isset($destination['country']) && is_string($destination['country']) ? trim($destination['country']) : '';
        $checkoutDebug = $this->isDebugRequested($destination);

        $payload = [
            'sender' => $sender,
            'to' => [
                'postcode' => $postcode,
                'country' => $country,
            ],
            'weight' => $this->packageWeightGrams($package),
            'estimatedCost' => $this->packageCost($package),
            'payment' => 0,
            'timeout' => 30,
        ];
        if ($checkoutDebug) {
            $payload['debug'] = true;
            $payload['clearCache'] = true;
        }

        $deliveryServices = $this->deliveryServiceIds($options);
        if ($deliveryServices !== []) {
            $payload['deliveryServices'] = $deliveryServices;
        }

        CheckoutSession::storeContext([
            'city' => isset($destination['city']) && is_string($destination['city']) ? trim($destination['city']) : '',
            'postcode' => $postcode,
            'country' => $country,
            'debug' => $checkoutDebug,
            'localityId' => $this->resolveLocalityIdByPostcode(
                $postcode,
                $country,
                isset($destination['city']) && is_string($destination['city']) ? trim($destination['city']) : ''
            ),
        ]);

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeRates(array $data): array
    {
        $rates = is_array($data['rates'] ?? null) ? $data['rates'] : [];
        $deliveryServices = $this->deliveryServices($data);
        $out = [];
        foreach ($rates as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $deliveryServiceId = $this->nestedInt($row, ['deliveryService', 'id'])
                ?: $this->intValue($row['deliveryService'] ?? null)
                ?: $this->intValue($row['deliveryServiceId'] ?? null);
            if ($deliveryServiceId <= 0) {
                continue;
            }

            $rateId = $this->intValue($row['id'] ?? null)
                ?: $this->intValue($row['rate'] ?? null)
                ?: $this->intValue($row['rateId'] ?? null);
            $methodKind = $this->methodKind($row);
            $optionId = sprintf('%s:%s:%s:%s', ShippingMethod::METHOD_ID, $deliveryServiceId, $rateId ?: 'default', $idx);
            $carrierName = $this->nestedString($row, ['deliveryService', 'name']);
            $carrierLogo = $this->nestedString($row, ['deliveryService', 'logo']);
            if ($carrierName === '' && isset($deliveryServices[$deliveryServiceId]['name'])) {
                $carrierName = $deliveryServices[$deliveryServiceId]['name'];
            }
            if ($carrierLogo === '' && isset($deliveryServices[$deliveryServiceId]['logo'])) {
                $carrierLogo = $deliveryServices[$deliveryServiceId]['logo'];
            }

            $out[] = [
                'optionId' => $optionId,
                'deliveryService' => $deliveryServiceId,
                'rate' => $rateId > 0 ? $rateId : null,
                'methodKind' => $methodKind,
                'title' => $this->rateTitle($row, $deliveryServiceId, $carrierName, $methodKind),
                'cost' => $this->moneyValue($row['deliveryPrice'] ?? $row['price'] ?? $row['cost'] ?? 0),
                'carrierName' => $carrierName,
                'carrierLogo' => $carrierLogo,
                'servicePoints' => $this->normalizeServicePoints(
                    is_array($row['servicePoints'] ?? null) ? $row['servicePoints'] : ($data['servicePoints'] ?? []),
                    $deliveryServiceId,
                    $methodKind
                ),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function extractDeliveryOptions(array $source): array
    {
        $settings = is_array($source['settings'] ?? null) ? $source['settings'] : $source;
        $deliveryServices = is_array($settings['DeliveryServices'] ?? null) ? $settings['DeliveryServices'] : [];

        return is_array($deliveryServices['options'] ?? null) ? $deliveryServices['options'] : [];
    }

    /**
     * @param array<string, mixed> $package
     */
    private function packageCost(array $package): float
    {
        foreach (['contents_cost', 'cart_subtotal', 'subtotal'] as $key) {
            if (isset($package[$key]) && is_numeric($package[$key])) {
                return round((float) $package[$key], 2);
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function packageWeightGrams(array $package): float
    {
        $grams = 0.0;
        $contents = is_array($package['contents'] ?? null) ? $package['contents'] : [];
        foreach ($contents as $line) {
            if (! is_array($line)) {
                continue;
            }
            $product = $line['data'] ?? null;
            $quantity = isset($line['quantity']) && is_numeric($line['quantity']) ? (float) $line['quantity'] : 1.0;
            if (is_object($product) && method_exists($product, 'get_weight')) {
                $weight = $product->get_weight();
                if (is_numeric($weight)) {
                    $grams += $this->storeWeightToGrams((float) $weight) * $quantity;
                }
            }
        }

        return round(max(self::MIN_PACKAGE_WEIGHT_GRAMS, $grams), 2);
    }

    private function storeWeightToGrams(float $weight): float
    {
        $unit = function_exists('get_option') ? (string) get_option('woocommerce_weight_unit', 'kg') : 'kg';

        return match (strtolower($unit)) {
            'g' => $weight,
            'lbs' => $weight * 453.59237,
            'oz' => $weight * 28.349523125,
            default => $weight * 1000,
        };
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<int>
     */
    private function deliveryServiceIds(array $options): array
    {
        $rows = is_array($options['carrierMapping'] ?? null) ? $options['carrierMapping'] : Options::getCarrierMappingRows();
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['deliveryService']) && is_numeric($row['deliveryService'])) {
                $id = (int) $row['deliveryService'];
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    private function resolveLocalityIdByPostcode(string $postcode, string $country, string $city): ?int
    {
        if ($postcode === '') {
            return null;
        }

        $result = $this->apiClient->fetchDeliveryServicePostcodesByExtId($postcode, 1);
        $postcodes = $result['items'];
        if ($postcodes === []) {
            return null;
        }

        $bestId = null;
        $bestScore = -1;
        foreach ($postcodes as $postcodeRow) {
            $row = $this->localityFromDeliveryServicePostcode($postcodeRow);
            if ($row === null) {
                continue;
            }
            $id = $this->intValue($row['id'] ?? null);
            if ($id <= 0) {
                continue;
            }

            $rowCountry = $this->localityCountryCode($row);
            if ($country !== '' && $rowCountry !== '' && strcasecmp($rowCountry, $country) !== 0) {
                continue;
            }

            $score = 0;
            if ($this->localityPostcode($row) === $postcode) {
                $score += 4;
            }
            if ($country !== '' && $rowCountry !== '' && strcasecmp($rowCountry, $country) === 0) {
                $score += 3;
            }
            if ($city !== '' && $this->localityNameMatches($row, $city)) {
                $score += 2;
            }
            if (count($postcodes) === 1) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestId = $id;
                $bestScore = $score;
            }
        }

        return $bestId;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function localityFromDeliveryServicePostcode(array $row): ?array
    {
        foreach ([['locality'], ['_embedded', 'locality']] as $path) {
            $value = $row;
            foreach ($path as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_array($value)) {
                return $value + [
                    'postcode' => $this->deliveryServicePostcodeExtId($row),
                ];
            }
            if (is_numeric($value)) {
                return [
                    'id' => (int) $value,
                    'postcode' => $this->deliveryServicePostcodeExtId($row),
                ];
            }
        }
        $embedded = $row['_embedded'] ?? null;
        if (is_array($embedded) && isset($embedded['localities']) && is_array($embedded['localities'])) {
            foreach ($embedded['localities'] as $locality) {
                if (is_array($locality)) {
                    return $locality + [
                        'postcode' => $this->deliveryServicePostcodeExtId($row),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function deliveryServicePostcodeExtId(array $row): string
    {
        foreach (['extId', 'ext_id', 'postcode', 'postalCode', 'postal_code', 'zip'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                return trim($row[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function localityPostcode(array $row): string
    {
        foreach (['postcode', 'postalCode', 'postal_code', 'zip'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                return trim($row[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function localityCountryCode(array $row): string
    {
        foreach ([['country'], ['_embedded', 'country']] as $path) {
            $value = $row;
            foreach ($path as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_array($value) && isset($value['code']) && is_string($value['code'])) {
                return trim($value['code']);
            }
        }
        foreach (['countryCode', 'country_code'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                return trim($row[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function localityNameMatches(array $row, string $city): bool
    {
        $needle = mb_strtolower(trim($city));
        foreach (['name', 'queryName', 'fullName'] as $key) {
            if (! isset($row[$key]) || ! is_string($row[$key])) {
                continue;
            }
            if (str_contains(mb_strtolower(trim($row[$key])), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function methodKind(array $row): string
    {
        $raw = strtolower((string) ($row['type'] ?? $row['rateType'] ?? $row['methodKind'] ?? ''));
        if (str_contains($raw, 'self_service_point') || str_contains($raw, 'locker') || str_contains($raw, 'box')) {
            return 'locker';
        }
        if (str_contains($raw, 'service_point') || str_contains($raw, 'office')) {
            return 'office';
        }

        return 'address';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rateTitle(array $row, int $deliveryServiceId, string $carrierName, string $methodKind): string
    {
        $rateName = '';
        foreach (['name', 'title', 'label'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                $rateName = trim($row[$key]);
                break;
            }
        }
        $deliveryTarget = $this->deliveryTargetLabel($methodKind);
        if (
            $deliveryTarget !== ''
            && (
                $rateName === ''
                || $this->isDefaultRateName($rateName)
                || $this->isTargetRateName($rateName, $methodKind)
            )
        ) {
            if ($carrierName !== '') {
                return sprintf('%s - %s', $carrierName, $deliveryTarget);
            }

            return $deliveryTarget;
        }
        if ($deliveryTarget !== '' && $carrierName !== '' && $rateName !== '') {
            return sprintf('%s - %s - %s', $carrierName, $rateName, $deliveryTarget);
        }
        if ($carrierName !== '' && $rateName !== '' && ! str_contains(strtolower($rateName), strtolower($carrierName))) {
            return sprintf('%s - %s', $carrierName, $rateName);
        }
        if ($rateName !== '') {
            return $rateName;
        }
        if ($carrierName !== '') {
            return $carrierName;
        }

        return sprintf('Delivery service #%d', $deliveryServiceId);
    }

    private function deliveryTargetLabel(string $methodKind): string
    {
        return match ($methodKind) {
            'locker' => 'ДО АВТОМАТ',
            'office' => 'ДО ОФИС',
            'address' => 'ДО АДРЕС',
            'office_locker' => 'ДО ОФИС/АВТОМАТ',
            default => '',
        };
    }

    private function isDefaultRateName(string $rateName): bool
    {
        $normalized = mb_strtolower(trim($rateName));

        return str_contains($normalized, 'стандарт 24')
            || str_contains($normalized, 'standard 24')
            || $normalized === 'default'
            || $normalized === 'simple';
    }

    private function isTargetRateName(string $rateName, string $methodKind): bool
    {
        $normalized = mb_strtolower(trim($rateName));

        return match ($methodKind) {
            'locker' => str_contains($normalized, 'автомат') || str_contains($normalized, 'locker'),
            'office' => str_contains($normalized, 'офис') || str_contains($normalized, 'office'),
            'address' => str_contains($normalized, 'адрес') || str_contains($normalized, 'address'),
            default => false,
        };
    }

    /**
     * @param mixed $items
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeServicePoints(mixed $items, int $deliveryServiceId, string $methodKind): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemDeliveryServiceId = $this->nestedInt($item, ['deliveryService', 'id']) ?: $this->intValue($item['deliveryService'] ?? null);
            if ($itemDeliveryServiceId > 0 && $itemDeliveryServiceId !== $deliveryServiceId) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            if ($methodKind === 'locker' && $type !== 'self_service_point') {
                continue;
            }
            if ($methodKind === 'office' && $type !== 'service_point') {
                continue;
            }
            $id = $this->intValue($item['id'] ?? null);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($item['name'] ?? ''),
                'address' => (string) ($item['address'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
            ];
        }

        return $out;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function moneyValue(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array{name?: string, logo?: string}>
     */
    private function deliveryServices(array $data): array
    {
        $items = is_array($data['deliveryServices'] ?? null) ? $data['deliveryServices'] : [];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $this->intValue($item['id'] ?? null);
            $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
            $logo = isset($item['logo']) && is_string($item['logo']) ? trim($item['logo']) : '';
            if ($id <= 0) {
                continue;
            }
            $row = [];
            if ($name !== '') {
                $row['name'] = $name;
            }
            if ($logo !== '') {
                $row['logo'] = $logo;
            }
            if ($row !== []) {
                $out[$id] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $path
     */
    private function nestedInt(array $row, array $path): int
    {
        $value = $row;
        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return 0;
            }
            $value = $value[$segment];
        }

        return $this->intValue($value);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $path
     */
    private function nestedString(array $row, array $path): string
    {
        $value = $row;
        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return '';
            }
            $value = $value[$segment];
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $package
     * @param array<string, mixed> $destination
     *
     * @return array<string, mixed>
     */
    private function packageDiagnostics(array $package, array $destination): array
    {
        return [
            'destination_city' => isset($destination['city']) && is_string($destination['city']) ? trim($destination['city']) : '',
            'destination_state' => isset($destination['state']) && is_string($destination['state']) ? trim($destination['state']) : '',
            'destination_postcode' => isset($destination['postcode']) && is_string($destination['postcode']) ? trim($destination['postcode']) : '',
            'destination_country' => isset($destination['country']) && is_string($destination['country']) ? trim($destination['country']) : '',
            'checkout_debug' => $this->isDebugRequested($destination),
            'package_weight_grams' => $this->packageWeightGrams($package),
            'package_cost' => $this->packageCost($package),
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function calculatorLogContext(array $package, array $payload, array $result): array
    {
        $destination = is_array($package['destination'] ?? null) ? $package['destination'] : [];

        return $this->packageDiagnostics($package, $destination) + [
            'source_id' => Options::getSourceId(),
            'sender' => $payload['sender'] ?? null,
            'debug_enabled' => $payload['debug'] ?? false,
            'clear_cache' => $payload['clearCache'] ?? false,
            'delivery_services' => $payload['deliveryServices'] ?? [],
            'request' => is_array($result['request'] ?? null) ? $result['request'] : PluginLog::requestFromOutbound(
                'POST',
                rtrim($this->apiClient->getBaseUrl(), '/') . '/api/delivery-services/calculator',
                ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                $payload
            ),
        ] + PluginLog::responseFromFetched(
            (int) ($result['status'] ?? 0),
            is_array($result['response_headers'] ?? null) ? $result['response_headers'] : [],
            (string) ($result['raw'] ?? ''),
            is_array($result['data'] ?? null) ? $result['data'] : null
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logCalculator(string $level, string $reason, array $context): void
    {
        if (($context['checkout_debug'] ?? false) !== true) {
            return;
        }

        PluginLog::log($level, 'checkout_calculator', ['reason' => $reason] + $context);
    }

    /**
     * @param array<string, mixed> $destination
     */
    private function isDebugRequested(array $destination): bool
    {
        foreach (['address_2', 'address2', 'addressLine2', 'address_line_2'] as $key) {
            if (isset($destination[$key]) && is_string($destination[$key]) && trim($destination[$key]) === 'DEBUG') {
                return true;
            }
        }

        foreach (['shipping_address_2', 'billing_address_2'] as $key) {
            if (! isset($_POST[$key])) {
                continue;
            }
            $value = function_exists('wp_unslash') ? wp_unslash($_POST[$key]) : $_POST[$key];
            if (is_string($value) && trim($value) === 'DEBUG') {
                return true;
            }
        }

        return false;
    }
}
