<?php
/**
 * Plugin Name: OctavaWMS WooCommerce Labels
 * Description: Adds OctavaWMS shipping label generation and download actions to WooCommerce orders.
 * Version: 1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/LabelService.php';
require_once __DIR__ . '/src/AdminLabelActions.php';

add_action('plugins_loaded', static function () {
    if (! function_exists('wc_get_logger')) {
        return;
    }

    $labelService = new OctavaWMS\WooCommerce\LabelService();
    $adminActions = new OctavaWMS\WooCommerce\AdminLabelActions($labelService);
    $adminActions->register();
});
