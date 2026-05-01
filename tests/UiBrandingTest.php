<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\UiBranding;
use OctavaWMS\WooCommerce\I18n\BrandedStrings;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class UiBrandingTest extends TestCase
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

    public function testDefaultsWhenNoDomainHints(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_' . Options::INTEGRATION_ID . '_settings') {
                return ['api_key' => 'x'];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        self::assertNull(UiBranding::currentBrandPack());
        self::assertSame('OctavaWMS Connector', UiBranding::integrationTitle());
        self::assertSame('Shipment', UiBranding::shipmentHeadingWord());
    }

    public function testIzpratiPackFromOauthDomainSlug(): void
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

        self::assertSame(UiBranding::PACK_IZPRATI, UiBranding::currentBrandPack());
        self::assertSame('Изпрати.БГ: Създай товарителница', UiBranding::integrationTitle());
        self::assertSame('Пратка', UiBranding::shipmentHeadingWord());
    }

    public function testIzpratiPackFromHostInBaseUrl(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_' . Options::INTEGRATION_ID . '_settings') {
                return [
                    'api_key' => 'x',
                    'label_endpoint' => 'https://tenant.izprati.bg/apps/woocommerce/api/label',
                ];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        self::assertSame(UiBranding::PACK_IZPRATI, UiBranding::currentBrandPack());
        self::assertSame('Изпрати.БГ: Създай товарителница', UiBranding::integrationTitle());
    }
}
