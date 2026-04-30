<?php
/**
 * Plugin Name: OctavaWMS Connector
 * Description: Connects WooCommerce to OctavaWMS. Includes shipping label generation, one-click connect, and is built to add more features over time.
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
        __DIR__ . '/src/PluginLog.php',
        __DIR__ . '/src/WooRestCredentials.php',
        __DIR__ . '/src/Activation.php',
        __DIR__ . '/src/Api/BackendApiClient.php',
        __DIR__ . '/src/Api/LabelService.php',
        __DIR__ . '/src/ConnectService.php',
        __DIR__ . '/src/Admin/LabelMetaBox.php',
        __DIR__ . '/src/Admin/LabelAjax.php',
        __DIR__ . '/src/AdminLabelActions.php',
        __DIR__ . '/src/Notices.php',
    ];
    foreach ($octavawmsWooAutoload as $file) {
        if (is_readable($file)) {
            require_once $file;
        }
    }
}

register_activation_hook(__FILE__, [OctavaWMS\WooCommerce\Activation::class, 'run']);

add_action('plugins_loaded', static function () {
    if (! function_exists('wc_get_logger') || ! class_exists(\WooCommerce::class, false)) {
        return;
    }

    if (is_readable(__DIR__ . '/src/SettingsPage.php') && class_exists(\WC_Integration::class, false)) {
        require_once __DIR__ . '/src/SettingsPage.php';
        if (class_exists(\OctavaWMS\WooCommerce\SettingsPage::class, false)) {
            add_filter('woocommerce_integrations', static function (array $list): array {
                $list[] = new \OctavaWMS\WooCommerce\SettingsPage();

                return $list;
            });
        }
    }

    $connect = new \OctavaWMS\WooCommerce\ConnectService();
    $connect->register();

    $apiClient = new \OctavaWMS\WooCommerce\Api\BackendApiClient();
    $labelService = new \OctavaWMS\WooCommerce\Api\LabelService($apiClient);
    $labelMetaBox = new \OctavaWMS\WooCommerce\Admin\LabelMetaBox();
    $labelAjax = new \OctavaWMS\WooCommerce\Admin\LabelAjax($apiClient, $labelService, $labelMetaBox);
    $adminActions = new \OctavaWMS\WooCommerce\AdminLabelActions($labelService, $labelMetaBox, $labelAjax);
    $adminActions->register();
}, 5);

if (is_admin() && is_readable(__DIR__ . '/src/Notices.php')) {
    require_once __DIR__ . '/src/Notices.php';
    (new \OctavaWMS\WooCommerce\Notices())->register();
}
