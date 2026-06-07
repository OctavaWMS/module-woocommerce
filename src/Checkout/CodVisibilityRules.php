<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

use OctavaWMS\WooCommerce\Options;

final class CodVisibilityRules
{
    public const COD_GATEWAY_ID = 'cod';

    /** @var list<string> */
    private const VALID_DELIVERY_TYPES = ['any', 'simple', 'service_point', 'self_service_point', 'office_and_locker'];

    public function register(): void
    {
        add_filter('woocommerce_available_payment_gateways', [$this, 'filterAvailablePaymentGateways'], 20);
    }

    /**
     * @param array<string, mixed> $gateways
     *
     * @return array<string, mixed>
     */
    public function filterAvailablePaymentGateways(array $gateways): array
    {
        if (! array_key_exists(self::COD_GATEWAY_ID, $gateways)) {
            return $gateways;
        }

        $rateId = $this->selectedRateId();
        if (! CheckoutDeliveryService::isOrderadminRateId($rateId)) {
            return $gateways;
        }

        $rate = CheckoutSession::rate($rateId);
        if ($rate === null) {
            return $gateways;
        }

        if ($this->bestRuleMode(Options::getCodVisibilityRules(), $rate) === 'exclude') {
            unset($gateways[self::COD_GATEWAY_ID]);
        }

        return $gateways;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>|null
     */
    public static function validateAndNormalizeRows(array $rows): ?array
    {
        $normalized = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                return null;
            }
            if (self::rowIsEmpty($row)) {
                continue;
            }

            $mode = self::normalizeMode($row['mode'] ?? ($row['action'] ?? 'exclude'));
            $deliveryServiceId = self::positiveIntOrNull(self::nestedValue($row, 'deliveryService', 'delivery_service_id'));
            $rateId = self::positiveIntOrNull(self::nestedValue($row, 'rate', 'rate_id'));
            $deliveryType = self::normalizeDeliveryType(
                (string) (self::nestedValue($row, 'deliveryType', 'delivery_type') ?? ($row['methodKind'] ?? 'any'))
            );
            if ($mode === null || $deliveryType === null) {
                return null;
            }
            if (self::hasInvalidPositiveInt($row, 'deliveryService', 'delivery_service_id')) {
                return null;
            }
            if (self::hasInvalidPositiveInt($row, 'rate', 'rate_id')) {
                return null;
            }
            if ($deliveryServiceId === null && $rateId === null && $deliveryType === 'any') {
                continue;
            }

            $scope = $rateId !== null ? 'rate' : ($deliveryType !== 'any' ? 'delivery_type' : 'carrier');
            $carrierKey = $deliveryServiceId !== null ? 'delivery_service_' . $deliveryServiceId : 'all';
            $id = isset($row['id']) && is_string($row['id']) && trim($row['id']) !== ''
                ? trim($row['id'])
                : sprintf('woo-cod-rule-%d', $index + 1);

            $match = [
                'scope' => $scope,
                'delivery_type' => $deliveryType,
            ];
            if ($deliveryServiceId !== null) {
                $match['delivery_service_id'] = (string) $deliveryServiceId;
            }
            if ($rateId !== null) {
                $match['rate_id'] = (string) $rateId;
            }

            $normalized[] = [
                'id' => $id,
                'enabled' => ($row['enabled'] ?? true) !== false && (string) ($row['enabled'] ?? 'true') !== 'false',
                'carrier_key' => isset($row['carrier_key']) && is_string($row['carrier_key']) && trim($row['carrier_key']) !== ''
                    ? trim($row['carrier_key'])
                    : $carrierKey,
                'carrier_label' => isset($row['carrier_label']) && is_string($row['carrier_label']) ? trim($row['carrier_label']) : '',
                'payment_handle' => self::COD_GATEWAY_ID,
                'mode' => $mode,
                'match' => $match,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed> $rate
     */
    private function bestRuleMode(array $rules, array $rate): ?string
    {
        $bestMode = null;
        $bestSpecificity = 0;
        foreach ($rules as $rule) {
            if (($rule['enabled'] ?? true) === false || (string) ($rule['enabled'] ?? 'true') === 'false') {
                continue;
            }
            if ((string) ($rule['payment_handle'] ?? self::COD_GATEWAY_ID) !== self::COD_GATEWAY_ID) {
                continue;
            }

            $mode = self::normalizeMode($rule['mode'] ?? 'exclude');
            if ($mode === null) {
                continue;
            }

            $specificity = $this->ruleSpecificity($rule, $rate);
            if ($specificity <= 0) {
                continue;
            }

            if ($specificity > $bestSpecificity || ($specificity === $bestSpecificity && $mode === 'exclude')) {
                $bestMode = $mode;
                $bestSpecificity = $specificity;
            }
        }

        return $bestMode;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $rate
     */
    private function ruleSpecificity(array $rule, array $rate): int
    {
        $match = is_array($rule['match'] ?? null) ? $rule['match'] : $rule;
        $expectedService = self::positiveIntOrNull($match['delivery_service_id'] ?? ($rule['deliveryService'] ?? null));
        $expectedRate = self::positiveIntOrNull($match['rate_id'] ?? ($rule['rate'] ?? null));
        $expectedType = self::normalizeDeliveryType((string) ($match['delivery_type'] ?? ($rule['deliveryType'] ?? 'any'))) ?? 'any';

        $actualService = self::positiveIntOrNull($rate['deliveryService'] ?? null);
        $actualRate = self::positiveIntOrNull($rate['rate'] ?? null);
        $actualType = self::deliveryTypeFromRate($rate);

        if ($expectedService !== null && $expectedService !== $actualService) {
            return 0;
        }
        if ($expectedType !== 'any' && ! self::deliveryTypeMatches($expectedType, $actualType)) {
            return 0;
        }
        if ($expectedRate !== null && $expectedRate !== $actualRate) {
            return 0;
        }
        if ($expectedService === null && $expectedRate === null && $expectedType === 'any') {
            return 0;
        }

        $specificity = 0;
        if ($expectedService !== null) {
            $specificity += 1;
        }
        if ($expectedType !== 'any') {
            $specificity += 2;
        }
        if ($expectedRate !== null) {
            $specificity += 4;
        }

        return $specificity;
    }

    private function selectedRateId(): string
    {
        $posted = $_POST['shipping_method'] ?? null;
        $selected = $this->firstOrderadminRateId($posted);
        if ($selected !== '') {
            return $selected;
        }

        if (function_exists('WC')) {
            $wc = WC();
            if (is_object($wc) && isset($wc->session) && is_object($wc->session) && method_exists($wc->session, 'get')) {
                $selected = $this->firstOrderadminRateId($wc->session->get('chosen_shipping_methods', []));
                if ($selected !== '') {
                    return $selected;
                }
            }
        }

        return isset($_POST['octavawms_delivery_rate_id']) && is_string($_POST['octavawms_delivery_rate_id'])
            ? sanitize_text_field((string) wp_unslash($_POST['octavawms_delivery_rate_id']))
            : '';
    }

    private function firstOrderadminRateId(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $selected = $this->firstOrderadminRateId($item);
                if ($selected !== '') {
                    return $selected;
                }
            }

            return '';
        }

        return is_string($value) && CheckoutDeliveryService::isOrderadminRateId($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowIsEmpty(array $row): bool
    {
        if (($row['enabled'] ?? null) === false || (string) ($row['enabled'] ?? '') === 'false') {
            return false;
        }

        return self::nestedValue($row, 'deliveryService', 'delivery_service_id') === null
            && self::nestedValue($row, 'rate', 'rate_id') === null
            && self::normalizeDeliveryType((string) (self::nestedValue($row, 'deliveryType', 'delivery_type') ?? ($row['methodKind'] ?? 'any'))) === 'any';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nestedValue(array $row, string $flatKey, string $matchKey): mixed
    {
        if (array_key_exists($flatKey, $row)) {
            return $row[$flatKey];
        }
        if (array_key_exists($matchKey, $row)) {
            return $row[$matchKey];
        }
        $match = $row['match'] ?? null;
        if (is_array($match) && array_key_exists($matchKey, $match)) {
            return $match[$matchKey];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hasInvalidPositiveInt(array $row, string $flatKey, string $matchKey): bool
    {
        $value = self::nestedValue($row, $flatKey, $matchKey);
        if ($value === null || $value === '') {
            return false;
        }

        return self::positiveIntOrNull($value) === null;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function normalizeMode(mixed $value): ?string
    {
        $mode = strtolower(trim((string) $value));
        if ($mode === 'hide') {
            return 'exclude';
        }
        if ($mode === 'allow') {
            return 'include';
        }

        return in_array($mode, ['exclude', 'include'], true) ? $mode : null;
    }

    private static function normalizeDeliveryType(string $value): ?string
    {
        $type = strtolower(trim($value));
        $map = [
            '' => 'any',
            'address' => 'simple',
            'office' => 'service_point',
            'locker' => 'self_service_point',
            'office_locker' => 'office_and_locker',
        ];
        $type = $map[$type] ?? $type;

        return in_array($type, self::VALID_DELIVERY_TYPES, true) ? $type : null;
    }

    /**
     * @param array<string, mixed> $rate
     */
    private static function deliveryTypeFromRate(array $rate): string
    {
        $kind = strtolower(trim((string) ($rate['methodKind'] ?? 'address')));

        return match ($kind) {
            'office' => 'service_point',
            'locker' => 'self_service_point',
            'office_locker' => 'office_and_locker',
            default => 'simple',
        };
    }

    private static function deliveryTypeMatches(string $expected, string $actual): bool
    {
        if ($expected === 'any') {
            return true;
        }
        if ($expected === 'office_and_locker') {
            return in_array($actual, ['service_point', 'self_service_point', 'office_and_locker'], true);
        }

        return $expected === $actual;
    }
}
