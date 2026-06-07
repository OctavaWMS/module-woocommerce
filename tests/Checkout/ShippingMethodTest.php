<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Checkout;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Checkout\ShippingMethod;
use OctavaWMS\WooCommerce\I18n\BrandedStrings;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\UiBranding;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class ShippingMethodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('apply_filters')->alias(static function (string $_hook, $value, ...$_args) {
            return $value;
        });
        Functions\when('__')->alias(static function ($text, $domain = 'octavawms') {
            $d = is_string($domain) ? $domain : 'octavawms';
            if ($d !== 'octavawms') {
                return $text;
            }
            $pack = UiBranding::currentBrandPack();
            $hit = BrandedStrings::overrideForBrand($pack, (string) $text);

            return $hit ?? $text;
        });
    }

    public function testUsesAppNameForMethodAndCheckoutLabels(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_' . Options::INTEGRATION_ID . '_settings') {
                return [
                    'api_key' => 'x',
                    'oauth_domain' => 'izpratibg',
                ];
            }

            return $default;
        });

        $method = new ShippingMethod();

        self::assertSame('Изпрати.БГ', $method->method_title);
        self::assertSame('Изпрати.БГ', $method->title);
        self::assertSame(
            'Изчислени тарифи за доставка от Изпрати.БГ.',
            $method->method_description
        );
    }
}
