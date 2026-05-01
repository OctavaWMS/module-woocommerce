<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

/**
 * Resolves which brand “pack” applies from backend hints (oauth domain / API host).
 * Copy overrides live in catalog PHP files wired by {@see I18n\BrandedStrings}.
 */
final class UiBranding
{
    public const PACK_IZPRATI = 'izprati';

    public static function integrationTitle(): string
    {
        return __('OctavaWMS Connector', 'octavawms');
    }

    public static function shipmentHeadingWord(): string
    {
        return __('Shipment', 'octavawms');
    }

    /** Current brand pack or null when default Octava copy (no tenant catalog). */
    public static function currentBrandPack(): ?string
    {
        return self::resolvePack();
    }

    private static function resolvePack(): ?string
    {
        $hints = self::domainHints();
        $detected = null;
        foreach ($hints as $hint) {
            if (self::hintMatchesIzprati($hint)) {
                $detected = self::PACK_IZPRATI;
                break;
            }
        }

        /** @var string|null $filtered */
        $filtered = apply_filters('octavawms_brand_pack', $detected, $hints);

        return is_string($filtered) && $filtered !== '' ? $filtered : null;
    }

    /**
     * @return list<string>
     */
    private static function domainHints(): array
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

    private static function hintMatchesIzprati(string $hint): bool
    {
        $raw = strtolower(trim($hint));
        if ($raw === '') {
            return false;
        }
        if ($raw === 'izpratibg') {
            return true;
        }
        if (str_contains($raw, '.')) {
            $raw = (string) (preg_replace('/^www\./', '', $raw) ?? $raw);
        }

        return str_contains($raw, 'izprati');
    }
}
