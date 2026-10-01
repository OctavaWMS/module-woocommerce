<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

final class WooCompatibility
{
    /** @return list<string> */
    public static function features(): array
    {
        return [
            'custom_order_tables',
            'cart_checkout_blocks',
        ];
    }

    public static function declare(string $pluginFile): void
    {
        $featuresUtil = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
        if (! class_exists($featuresUtil)) {
            return;
        }

        foreach (self::features() as $feature) {
            $featuresUtil::declare_compatibility($feature, $pluginFile, true);
        }
    }
}
