<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\I18n;

use OctavaWMS\WooCommerce\UiBranding;

/**
 * Applies brand/module-specific gettext overrides for domain {@see octavawms}.
 *
 * Canonical English strings are passed through {@see __()} in code; catalogs map
 * msgid → copy for tenants (e.g. Изпрати.БГ) under {@see src/I18n/catalogs/}.
 */
final class BrandedStrings
{
    public static function register(): void
    {
        add_filter('gettext', [self::class, 'filterGettext'], 10, 3);
    }

    /**
     * @param mixed $translation
     * @return string|mixed
     */
    public static function filterGettext($translation, string $text, string $domain)
    {
        if ($domain !== 'octavawms') {
            return $translation;
        }

        $packed = self::overrideForBrand(UiBranding::currentBrandPack(), $text);

        return $packed ?? $translation;
    }

    /**
     * Returns catalog copy for msgid when a pack defines it (for tests / tooling).
     */
    public static function overrideForBrand(?string $pack, string $msgidEnglish): ?string
    {
        if ($pack === null || $pack === '') {
            return null;
        }

        foreach (self::catalogPaths($pack) as $path) {
            if (! is_readable($path)) {
                continue;
            }

            /** @var array<string, string> $map */
            $map = require $path;
            if (isset($map[$msgidEnglish]) && is_string($map[$msgidEnglish]) && $map[$msgidEnglish] !== '') {
                return $map[$msgidEnglish];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function catalogPaths(string $pack): array
    {
        $dir = dirname(__DIR__) . '/I18n/catalogs';

        return match ($pack) {
            UiBranding::PACK_IZPRATI => [$dir . '/izprati-bg.php'],
            default => [],
        };
    }
}
