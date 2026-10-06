<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Distribution;
use OctavaWMS\WooCommerce\UiBranding;

final class DistributionTest extends TestCase
{
    public function testMarketplaceDefaultsAreIzpratiBranded(): void
    {
        self::assertSame('1.6.2', Distribution::VERSION);
        self::assertSame('Изпрати.БГ Shipping for WooCommerce', Distribution::PRODUCT_NAME);
        self::assertSame('izprati-bg-shipping', Distribution::PLUGIN_SLUG);
        self::assertSame(UiBranding::PACK_IZPRATI, Distribution::defaultBrandPack(null));
        self::assertSame(
            'https://api.izprati.bg/apps/woocommerce/connect',
            Distribution::defaultConnectUrl('https://pro.oawms.com/apps/woocommerce/connect')
        );
    }

    public function testDetectedTenantBrandStillWins(): void
    {
        self::assertSame('another-brand', Distribution::defaultBrandPack('another-brand'));
    }
}
