<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use WC_Order;
use WC_Order_Item_Product;

/**
 * Helpers for deriving label defaults from WooCommerce order line items (core WC_Order has no guaranteed total weight API).
 */
final class WooOrderWeights
{
    /**
     * Combined product weight × quantity in WooCommerce store units.
     *
     * Uses WC_Order::get_total_weight() when implemented and returning a positive value (extensions).
     */
    public static function contentsWeightTotal(WC_Order $order): float
    {
        if (method_exists($order, 'get_total_weight')) {
            $direct = $order->get_total_weight();
            if (is_numeric($direct) && (float) $direct > 0.0) {
                return (float) $direct;
            }
        }

        $total = 0.0;
        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if ($product === false || null === $product) {
                continue;
            }
            if (method_exists($product, 'has_weight') && ! $product->has_weight()) {
                continue;
            }
            $pw = $product->get_weight();
            if ($pw === '' || ! is_numeric($pw)) {
                continue;
            }
            $qty = (float) $item->get_quantity();
            if ($qty <= 0.0) {
                continue;
            }
            $total += (float) $pw * $qty;
        }

        return $total;
    }

    /** Convert weight in WooCommerce unit setting into grams */
    public static function toGrams(float $weight, string $unit): float
    {
        return match (strtolower($unit)) {
            'kg' => $weight * 1000.0,
            'lbs' => $weight * 453.592,
            'oz' => $weight * 28.3495,
            default => $weight,
        };
    }
}
