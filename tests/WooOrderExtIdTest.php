<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\WooOrderExtId;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class WooOrderExtIdTest extends TestCase
{
    public function testImportFilterUsesNumericOrderIdWhenMetaEmpty(): void
    {
        $order = new \WC_Order(49184, 'wc_order_abc123def', '');
        self::assertSame('49184', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterUsesCanonicalMetaWhenNotOrderKey(): void
    {
        $order = new \WC_Order(42, 'wc_order_k', 'ERP-999');
        self::assertSame('ERP-999', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterIgnoresMetaWhenMatchesOrderKey(): void
    {
        $order = new \WC_Order(12, 'wc_order_shared', 'wc_order_shared');
        self::assertSame('12', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterIgnoresMetaWhenWcOrderPrefix(): void
    {
        $order = new \WC_Order(7, 'other_key', 'wc_order_stale_from_meta');
        self::assertSame('7', WooOrderExtId::importFilterExtId($order));
    }
}
