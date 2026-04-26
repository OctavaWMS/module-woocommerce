<?php
/**
 * Plugin Name: OctavaWMS WooCommerce Labels
 * Description: Adds OctavaWMS shipping label generation, one-click connect, and download actions to WooCommerce orders.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.1
 * Text Domain: octavawms
 */

if (! defined('ABSPATH')) {
    exit;
}

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    $octavawmsWooAutoload = [
        __DIR__ . '/src/Options.php',
        __DIR__ . '/src/Activation.php',
        __DIR__ . '/src/LabelService.php',
        __DIR__ . '/src/ConnectService.php',
        __DIR__ . '/src/AdminLabelActions.php',
        __DIR__ . '/src/Notices.php',
    ];
    foreach ($octavawmsWooAutoload as $file) {
        if (is_readable($file)) {
            require_once $file;
        }
    }
}

register_activation_hook(__FILE__, [OctavawMS\WooCommerce\Activation::class, 'run']);

add_action('plugins_loaded', static function () {
    if (! function_exists('wc_get_logger') || ! class_exists(\WooCommerce::class, false)) {
        return;
    }

    if (is_readable(__DIR__ . '/src/SettingsPage.php') && class_exists(\WC_Integration::class, false)) {
        require_once __DIR__ . '/src/SettingsPage.php';
        if (class_exists(\OctavawMS\WooCommerce\SettingsPage::class, false)) {
            add_filter('woocommerce_integrations', static function (array $list): array {
                $list[] = new \OctavawMS\WooCommerce\SettingsPage();
                return $list;
            });
        }
    }

    $connect = new \OctavawMS\WooCommerce\ConnectService();
    $connect->register();

    $labelService = new \OctavawMS\WooCommerce\LabelService();
    $adminActions = new \OctavawMS\WooCommerce\AdminLabelActions($labelService);
    $adminActions->register();
}, 5);

if (is_admin() && is_readable(__DIR__ . '/src/Notices.php')) {
    require_once __DIR__ . '/src/Notices.php';
    (new \OctavawMS\WooCommerce\Notices())->register();
}
