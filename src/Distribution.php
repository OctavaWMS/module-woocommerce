<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

/** Marketplace distribution identity while internal identifiers stay backward compatible. */
final class Distribution
{
    public const VERSION = '1.6.3';

    public const PRODUCT_NAME = 'Изпрати.БГ Shipping for WooCommerce';

    public const PLUGIN_SLUG = 'izprati-bulgaria-shipping';

    public const CONNECT_URL = 'https://api.izprati.bg/apps/woocommerce/connect';

    public static function defaultBrandPack(?string $detected): string
    {
        return is_string($detected) && $detected !== '' ? $detected : UiBranding::PACK_IZPRATI;
    }

    public static function defaultConnectUrl(string $default): string
    {
        unset($default);

        return self::CONNECT_URL;
    }
}
