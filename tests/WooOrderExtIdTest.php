<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\WooOrderExtId;

final class WooOrderExtIdTest extends TestCase
{
    public function testImportFilterExtIdPrefersOctavaMeta(): void
    {
        $order = new \WC_Order(10, 'wc_key_meta', 'from-meta');
        self::assertSame('from-meta', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterExtIdUsesOrderNumberWhenNoMeta(): void
    {
        $order = new \WC_Order(100, 'wc_order_legacy', '', '5001');
        self::assertSame('5001', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterExtIdUsesIdWhenNumberEmpty(): void
    {
        $order = new \WC_Order(42, 'wc_fallback', '');
        self::assertSame('42', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterExtIdUsesNumericIdWhenOrderNumberIsWooOrderKey(): void
    {
        $key = 'wc_order_2V21SVLG4Mqfj';
        $order = new \WC_Order(44364, $key, '', $key);
        self::assertSame('44364', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterExtIdUsesNumericIdWhenOrderNumberLooksLikeKeyButDiffers(): void
    {
        $order = new \WC_Order(44364, 'wc_order_realKeyHere', '', 'wc_order_displayBug');
        self::assertSame('44364', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterExtIdIgnoresMetaWhenItIsWooOrderKeyAndOrderHasId(): void
    {
        $key = 'wc_order_badLegacyMeta';
        $order = new \WC_Order(9001, $key, $key, '');

        self::assertSame('9001', WooOrderExtId::importFilterExtId($order));
    }

    public function testImportFilterExtIdFallsBackToOrderKeyWhenIdZero(): void
    {
        $order = new \WC_Order(0, 'draft_wc_key', '');
        self::assertSame('draft_wc_key', WooOrderExtId::importFilterExtId($order));
    }

    public function testLookupCandidatesOrderMetaThenNumberThenIdThenKey(): void
    {
        $order = new \WC_Order(200, 'wc_last', '', 'ORD-200');
        self::assertSame(
            ['ORD-200', '200', 'wc_last'],
            WooOrderExtId::lookupCandidates($order)
        );
    }

    public function testLookupCandidatesMetaFirst(): void
    {
        $order = new \WC_Order(1, 'k', 'ext-99', '1');
        self::assertSame(
            ['ext-99', '1', 'k'],
            WooOrderExtId::lookupCandidates($order)
        );
    }
}
