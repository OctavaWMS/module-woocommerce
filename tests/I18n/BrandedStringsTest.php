<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\I18n;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\I18n\BrandedStrings;
use OctavaWMS\WooCommerce\UiBranding;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class BrandedStringsTest extends TestCase
{
    public function testIzpratiCatalogOverridesIntegrationTitle(): void
    {
        $out = BrandedStrings::overrideForBrand(UiBranding::PACK_IZPRATI, 'OctavaWMS Connector');
        self::assertSame('Изпрати.БГ', $out);
    }

    public function testIzpratiCatalogOverridesCheckoutPickupValidation(): void
    {
        $out = BrandedStrings::overrideForBrand(UiBranding::PACK_IZPRATI, 'Choose a pickup point before placing the order.');
        self::assertSame('Изберете пункт за получаване преди завършване на поръчката.', $out);
    }

    public function testUnknownPackReturnsNull(): void
    {
        self::assertNull(BrandedStrings::overrideForBrand(null, 'OctavaWMS Connector'));
        self::assertNull(BrandedStrings::overrideForBrand('no-such-pack', 'OctavaWMS Connector'));
    }

    public function testFilterGettextIgnoresWrongDomain(): void
    {
        self::assertSame('X', BrandedStrings::filterGettext('X', 'OctavaWMS Connector', 'other'));
    }

    public function testFilterGettextAppliesPublicPluginDomain(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $default
        );
        Functions\when('apply_filters')->alias(
            static fn (string $tag, mixed $value): mixed => $tag === 'octavawms_brand_pack'
                ? UiBranding::PACK_IZPRATI
                : $value
        );

        self::assertSame(
            'Изпрати.БГ',
            BrandedStrings::filterGettext(
                'OctavaWMS Connector',
                'OctavaWMS Connector',
                'izprati-bulgaria-shipping'
            )
        );
    }
}
