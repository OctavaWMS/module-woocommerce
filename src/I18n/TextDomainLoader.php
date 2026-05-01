<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\I18n;

/**
 * Loads {@see octavawms} MO files from {@see /languages} (e.g. octavawms-bg_BG.mo).
 */
final class TextDomainLoader
{
    public static function register(): void
    {
        add_action('plugins_loaded', [self::class, 'load'], 0);
    }

    public static function load(): void
    {
        if (! defined('OCTAVAWMS_PLUGIN_FILE')) {
            return;
        }

        $rel = dirname(plugin_basename(OCTAVAWMS_PLUGIN_FILE)) . '/languages';
        load_plugin_textdomain('octavawms', false, $rel);
    }
}
