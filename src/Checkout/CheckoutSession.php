<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

final class CheckoutSession
{
    private const RATES_KEY = 'octavawms_checkout_rates';
    private const CONTEXT_KEY = 'octavawms_checkout_context';
    private const SELECTED_KEY = 'octavawms_checkout_selection';

    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, mixed>> $rates
     */
    public static function storeRates(array $rates): void
    {
        self::set(self::RATES_KEY, $rates);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function rates(): array
    {
        $value = self::get(self::RATES_KEY, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function rate(string $rateId): ?array
    {
        $rates = self::rates();
        $rate = $rates[$rateId] ?? null;

        return is_array($rate) ? $rate : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function storeContext(array $context): void
    {
        self::set(self::CONTEXT_KEY, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        $value = self::get(self::CONTEXT_KEY, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $selection
     */
    public static function storeSelection(array $selection): void
    {
        self::set(self::SELECTED_KEY, $selection);
    }

    /**
     * @return array<string, mixed>
     */
    public static function selection(): array
    {
        $value = self::get(self::SELECTED_KEY, []);

        return is_array($value) ? $value : [];
    }

    private static function get(string $key, mixed $default): mixed
    {
        if (! function_exists('WC')) {
            return $GLOBALS['octavawms_checkout_session'][$key] ?? $default;
        }

        $wc = WC();
        if (is_object($wc) && isset($wc->session) && is_object($wc->session) && method_exists($wc->session, 'get')) {
            return $wc->session->get($key, $default);
        }

        return $GLOBALS['octavawms_checkout_session'][$key] ?? $default;
    }

    private static function set(string $key, mixed $value): void
    {
        if (function_exists('WC')) {
            $wc = WC();
            if (is_object($wc) && isset($wc->session) && is_object($wc->session) && method_exists($wc->session, 'set')) {
                $wc->session->set($key, $value);

                return;
            }
        }

        if (! isset($GLOBALS['octavawms_checkout_session']) || ! is_array($GLOBALS['octavawms_checkout_session'])) {
            $GLOBALS['octavawms_checkout_session'] = [];
        }
        $GLOBALS['octavawms_checkout_session'][$key] = $value;
    }
}
