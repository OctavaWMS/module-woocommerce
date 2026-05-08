<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Partners\PartnerModule;
use OctavaWMS\WooCommerce\Partners\PartnerModuleRegistry;
use OctavaWMS\WooCommerce\Partners\PartnerModuleResolver;

/**
 * Tenant-facing labels and brand pack (delegates to {@see PartnerModuleResolver}).
 * Copy overrides live in catalog PHP files wired by {@see I18n\BrandedStrings}.
 */
final class UiBranding
{
    /** @deprecated Use {@see PartnerModuleRegistry::BRAND_PACK_IZPRATI} */
    public const PACK_IZPRATI = PartnerModuleRegistry::BRAND_PACK_IZPRATI;

    public static function integrationTitle(): string
    {
        return __('OctavaWMS Connector', 'octavawms');
    }

    public static function shipmentHeadingWord(): string
    {
        return __('Shipment', 'octavawms');
    }

    public static function currentModule(): PartnerModule
    {
        return PartnerModuleResolver::resolve();
    }

    /** Current brand pack or null when default Octava copy (no tenant catalog). */
    public static function currentBrandPack(): ?string
    {
        $hints = self::domainHintsForFilters();
        $base = self::currentModule()->brandPack;
        /** @var string|null $filtered */
        $filtered = apply_filters('octavawms_brand_pack', $base, $hints);

        return is_string($filtered) && $filtered !== '' ? $filtered : null;
    }

    /**
     * @return list<string>
     */
    public static function domainHintsForFilters(): array
    {
        $out = [];
        $oauth = trim(Options::getOAuthDomain());
        if ($oauth !== '') {
            $out[] = $oauth;
        }

        $base = Options::getBaseUrl();
        $host = parse_url($base, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $out[] = $host;
        }

        return array_values(array_unique($out));
    }
}
