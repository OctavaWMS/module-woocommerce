<?php
/**
 * Plugin Name: OctavaWMS Connector
 * Description: Connects WooCommerce to OctavaWMS. Includes shipping label generation, one-click connect, and is built to add more features over time.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.1
 * Text Domain: octavawms
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('OCTAVAWMS_PLUGIN_FILE', __FILE__);

// Always resolve plugin classes from /src via PSR-4 (prepend so this wins over an incomplete Composer autoload).
spl_autoload_register(
    static function (string $class): void {
        $prefix = 'OctavaWMS\\WooCommerce\\';
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    },
    true,
    true
);

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

\OctavaWMS\WooCommerce\I18n\TextDomainLoader::register();

register_activation_hook(__FILE__, [OctavaWMS\WooCommerce\Activation::class, 'run']);

$octavawms_bootstrap_woocommerce = static function (): void {
    static $done = false;
    if ($done) {
        return;
    }

    /**
     * Prevents attaching the WooCommerce integrations filter twice if bootstrap retries after a fatal error mid-run.
     */
    static $integrationsFilterAttached = false;

    if (function_exists('add_filter')) {
        \OctavaWMS\WooCommerce\I18n\BrandedStrings::register();
    }
    if (! $integrationsFilterAttached && is_readable(__DIR__ . '/src/SettingsPage.php')
        && class_exists(\WC_Integration::class, false)) {
        $integrationsFilterAttached = true;
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
    \OctavaWMS\WooCommerce\Admin\SettingsAjax::registerAjax();
    $orderSync = new \OctavaWMS\WooCommerce\OrderSyncService($apiClient);
    $orderSync->register();
    $labelService = new \OctavaWMS\WooCommerce\Api\LabelService($apiClient);
    $labelMetaBox = new \OctavaWMS\WooCommerce\Admin\LabelMetaBox();
    $labelAjax = new \OctavaWMS\WooCommerce\Admin\LabelAjax($apiClient, $labelService, $labelMetaBox);
    $adminActions = new \OctavaWMS\WooCommerce\AdminLabelActions($labelService, $labelMetaBox, $labelAjax, $apiClient);
    $adminActions->register();

    $done = true;
};

// Register carrier-matrix AJAX early so admin-ajax always sees wp_ajax_* / wp_ajax_nopriv_* hooks,
// even if WooCommerce bootstrap timing defers the rest of the plugin. No BackendApiClient here.
add_action(
    'plugins_loaded',
    static function (): void {
        if (! function_exists('has_action')) {
            return;
        }
        $action = \OctavaWMS\WooCommerce\Admin\SettingsAjax::ACTION;
        if (has_action('wp_ajax_' . $action) && has_action('wp_ajax_nopriv_' . $action)) {
            return;
        }
        \OctavaWMS\WooCommerce\Admin\SettingsAjax::registerAjax();
    },
    20
);

// If hooks were removed or never attached, register before admin-ajax reaches admin_init.
add_action(
    'init',
    static function (): void {
        if (! function_exists('has_action')) {
            return;
        }
        $action = \OctavaWMS\WooCommerce\Admin\SettingsAjax::ACTION;
        if (has_action('wp_ajax_' . $action) && has_action('wp_ajax_nopriv_' . $action)) {
            return;
        }
        \OctavaWMS\WooCommerce\Admin\SettingsAjax::registerAjax();
    },
    0
);

// Run after WooCommerce is ready. `woocommerce_loaded` can fire before this plugin's file loads
// (plugin load order); use did_action so we still bootstrap in that case.
add_action(
    'plugins_loaded',
    static function () use ($octavawms_bootstrap_woocommerce): void {
        if (! function_exists('WC')) {
            return;
        }
        if (did_action('woocommerce_loaded')) {
            $octavawms_bootstrap_woocommerce();
        } else {
            add_action('woocommerce_loaded', $octavawms_bootstrap_woocommerce, 10);
        }
    },
    5
);

// Recover from load-order failures: WP 6+ returns wp_die('0', 400) when no wp_ajax_<action> hook exists yet.
add_action(
    'plugins_loaded',
    static function () use ($octavawms_bootstrap_woocommerce): void {
        if (! function_exists('WC')) {
            return;
        }
        $action = \OctavaWMS\WooCommerce\Admin\SettingsAjax::ACTION;
        if (! has_action('wp_ajax_' . $action) || ! has_action('wp_ajax_nopriv_' . $action)) {
            $octavawms_bootstrap_woocommerce();
        }
    },
    PHP_INT_MAX
);

if (is_admin() && is_readable(__DIR__ . '/src/Notices.php')) {
    require_once __DIR__ . '/src/Notices.php';
    (new \OctavaWMS\WooCommerce\Notices())->register();
}
