<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Partners;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\Partners\PartnerModule;
use OctavaWMS\WooCommerce\Partners\PartnerModuleRegistry;
use OctavaWMS\WooCommerce\Partners\PartnerModuleResolver;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class PartnerModuleResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('apply_filters')->alias(static function (string $_hook, $value, ...$_args) {
            return $value;
        });
    }

    public function testResolvesOctavaWhenNoHintsAndNoOverrides(): void
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

        $m = PartnerModuleResolver::resolve();
        self::assertSame(PartnerModuleRegistry::ID_OCTAVA, $m->id);
        self::assertNull($m->brandPack);
        self::assertSame('https://app.octavawms.com', $m->panelAppBase);
    }

    public function testHeuristicIzpratibgFromOauthDomain(): void
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

        $m = PartnerModuleResolver::resolve();
        self::assertSame(PartnerModuleRegistry::ID_IZPRATIBG, $m->id);
        self::assertSame(PartnerModuleRegistry::BRAND_PACK_IZPRATI, $m->brandPack);
        self::assertSame('https://app.izprati.bg', $m->panelAppBase);
    }

    public function testStoredPartnerModuleOverridesHeuristic(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_' . Options::INTEGRATION_ID . '_settings') {
                return [
                    'api_key' => 'x',
                    'oauth_domain' => 'izpratibg',
                    'partner_module' => PartnerModuleRegistry::ID_OCTAVA,
                ];
            }

            return $default;
        });

        $m = PartnerModuleResolver::resolve();
        self::assertSame(PartnerModuleRegistry::ID_OCTAVA, $m->id);
    }

    public function testFilterPartnerModuleStringIdWinsOverStored(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_' . Options::INTEGRATION_ID . '_settings') {
                return [
                    'api_key' => 'x',
                    'partner_module' => PartnerModuleRegistry::ID_OCTAVA,
                ];
            }

            return $default;
        });
        Functions\when('apply_filters')->alias(static function (string $hook, $value, ...$args) {
            if ($hook === 'octavawms_partner_module') {
                return PartnerModuleRegistry::ID_IZPRATIBG;
            }

            return $value;
        });

        $m = PartnerModuleResolver::resolve();
        self::assertSame(PartnerModuleRegistry::ID_IZPRATIBG, $m->id);
    }

    public function testFilterPartnerModuleInstanceWins(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_' . Options::INTEGRATION_ID . '_settings') {
                return [
                    'api_key' => 'x',
                    'partner_module' => PartnerModuleRegistry::ID_IZPRATIBG,
                ];
            }

            return $default;
        });

        $custom = new PartnerModule(
            'custom',
            'Custom',
            [],
            'https://panel.example.test',
            null,
            static fn (string $_h): bool => false,
        );

        Functions\when('apply_filters')->alias(static function (string $hook, $value, ...$args) use ($custom) {
            if ($hook === 'octavawms_partner_module') {
                return $custom;
            }

            return $value;
        });

        $m = PartnerModuleResolver::resolve();
        self::assertSame('custom', $m->id);
        self::assertSame('https://panel.example.test', $m->panelAppBase);
    }
}
