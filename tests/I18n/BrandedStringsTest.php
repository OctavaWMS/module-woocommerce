<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\I18n;

use OctavaWMS\WooCommerce\I18n\BrandedStrings;
use OctavaWMS\WooCommerce\UiBranding;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class BrandedStringsTest extends TestCase
{
    public function testIzpratiCatalogOverridesIntegrationTitle(): void
    {
        $out = BrandedStrings::overrideForBrand(UiBranding::PACK_IZPRATI, 'OctavaWMS Connector');
        self::assertSame('Изпрати.БГ: Създай товарителница', $out);
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
}
