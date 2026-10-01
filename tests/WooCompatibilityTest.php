<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\WooCompatibility;

final class WooCompatibilityTest extends TestCase
{
    public function testDeclaresMarketplaceRequiredWooFeatures(): void
    {
        self::assertSame(
            ['custom_order_tables', 'cart_checkout_blocks'],
            WooCompatibility::features()
        );
    }
}
