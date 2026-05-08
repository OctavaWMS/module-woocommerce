<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Partners;

use OctavaWMS\WooCommerce\Options;

/**
 * Resolves which {@see PartnerModule} applies (Shopify {@see OCTAVA_APP_ID} + hints analogue).
 *
 * Order (first win):
 *  1. Filter {@see octavawms_partner_module} — may return {@see PartnerModule} or known module id string.
 *  2. Integration setting {@see Options::getPartnerModuleOverrideId()} (`partner_module` in WC settings array).
 *  3. PHP constant {@see OCTAVA_WOO_PARTNER_MODULE} (e.g. white-label zip from {@see scripts/package-release.sh}).
 *  4. Heuristic: OAuth domain + API base host vs each module’s hint matcher (Izprati before default Octava).
 */
final class PartnerModuleResolver
{
    public static function resolve(): PartnerModule
    {
        $hints = self::domainHintsFromOptions();

        /** @var mixed $fromFilter */
        $fromFilter = apply_filters('octavawms_partner_module', null, $hints);
        if ($fromFilter instanceof PartnerModule) {
            return $fromFilter;
        }
        if (is_string($fromFilter)) {
            $id = trim($fromFilter);
            if ($id !== '') {
                $m = PartnerModuleRegistry::byId($id);
                if ($m !== null) {
                    return $m;
                }
            }
        }

        $stored = Options::getPartnerModuleOverrideId();
        if ($stored !== '') {
            $m = PartnerModuleRegistry::byId($stored);
            if ($m !== null) {
                return $m;
            }
        }

        if (defined('OCTAVA_WOO_PARTNER_MODULE')) {
            $constant = constant('OCTAVA_WOO_PARTNER_MODULE');
            $id = is_string($constant) ? trim($constant) : '';
            if ($id !== '') {
                $m = PartnerModuleRegistry::byId($id);
                if ($m !== null) {
                    return $m;
                }
            }
        }

        foreach (self::modulesForHeuristic() as $module) {
            foreach ($hints as $hint) {
                if ($module->matchesHint($hint)) {
                    return $module;
                }
            }
        }

        return PartnerModuleRegistry::octava();
    }

    /**
     * @return list<PartnerModule>
     */
    private static function modulesForHeuristic(): array
    {
        return [
            PartnerModuleRegistry::izpratibg(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function domainHintsFromOptions(): array
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
