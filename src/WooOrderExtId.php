<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use WC_Order;

/**
 * Resolves which external identifiers we send to OctavaWMS for a WooCommerce order.
 */
final class WooOrderExtId
{
    /**
     * Values to try with GET /api/products/order (and shipments) until one matches.
     * Order matters: custom meta first (when set), then stable Woo keys commonly stored as extId.
     *
     * @return list<string>
     */
    public static function lookupCandidates(WC_Order $order): array
    {
        $out = [];
        $push = static function (string $s) use (&$out): void {
            $t = trim($s);
            if ($t !== '' && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        };

        $meta = (string) $order->get_meta('_octavawms_external_order_id', true);
        if ($meta !== '') {
            $push($meta);
        }
        $push((string) $order->get_order_key());
        $push((string) $order->get_id());
        $num = trim((string) $order->get_order_number());
        if ($num !== '' && $num !== (string) $order->get_id()) {
            $push($num);
        }

        return $out;
    }

    /**
     * extId used in POST /api/integrations/import sourceData.filters.
     * Uses the WooCommerce order id (shop order / HPOS id, e.g. the same numeric id as in `post.php?post=…`) so imports align with admin URLs.
     * Stored `_octavawms_external_order_id` is used only when it is not the WooCommerce secret order key (e.g. canonical id from the API).
     */
    public static function importFilterExtId(WC_Order $order): string
    {
        $meta = trim((string) $order->get_meta('_octavawms_external_order_id', true));
        if ($meta !== '' && ! self::metaIsWooCommerceOrderKey($meta, $order)) {
            return $meta;
        }

        return (string) $order->get_id();
    }

    /**
     * True when meta matches the internal Woo order key (often {@code wc_order_…}) and must not drive import extId.
     */
    private static function metaIsWooCommerceOrderKey(string $meta, WC_Order $order): bool
    {
        if ($meta === (string) $order->get_order_key()) {
            return true;
        }

        return str_starts_with($meta, 'wc_order_');
    }
}
