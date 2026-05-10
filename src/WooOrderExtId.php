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
     * Order matters: custom meta first (when set), then order number, post ID, then order key (legacy).
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
        $push(trim((string) $order->get_order_number()));
        $push((string) $order->get_id());
        $push((string) $order->get_order_key());

        return $out;
    }

    /**
     * extId used in POST /api/integrations/import sourceData.filters (legacy contract).
     */
    public static function importFilterExtId(WC_Order $order): string
    {
        $meta = trim((string) $order->get_meta('_octavawms_external_order_id', true));
        if ($meta !== '') {
            return $meta;
        }

        $num = trim((string) $order->get_order_number());
        if ($num !== '') {
            return $num;
        }

        if ($order->get_id() > 0) {
            return (string) $order->get_id();
        }

        return (string) $order->get_order_key();
    }
}
