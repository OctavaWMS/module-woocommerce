<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Checkout\CheckoutDeliveryService;
use OctavaWMS\WooCommerce\Notices;

final class ReviewDistributionTest extends TestCase
{
    public function testCheckoutLoadsMapLibraryFromPluginDirectory(): void
    {
        Functions\when('__')->returnArg();
        Functions\when('get_option')->alias(static fn ($key, $default = false) => $default);
        Functions\when('is_checkout')->justReturn(true);
        Functions\when('is_cart')->justReturn(false);
        Functions\when('is_order_received_page')->justReturn(false);
        Functions\when('plugins_url')->alias(static fn ($path, $plugin) => 'https://shop.test/wp-content/plugins/izprati-bulgaria-shipping/' . $path);
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('admin_url')->justReturn('https://shop.test/wp-admin/admin-ajax.php');
        Functions\when('wp_create_nonce')->justReturn('checkout-nonce');
        Functions\expect('wp_localize_script')->once()->with('octavawms-checkout-delivery', 'octavawmsCheckoutDelivery', \Mockery::on(static function (array $config): bool {
            self::assertSame('https://shop.test/wp-content/plugins/izprati-bulgaria-shipping/assets/lib/leaflet/leaflet-src.js', $config['leafletJsUrl']);
            self::assertSame('https://shop.test/wp-content/plugins/izprati-bulgaria-shipping/assets/lib/leaflet/leaflet.css', $config['leafletCssUrl']);
            return true;
        }));
        (new CheckoutDeliveryService(new BackendApiClient()))->enqueueAssets();
    }

    public function testNoticeDoesNotAppearOnOtherWooCommerceSettings(): void
    {
        $_GET = ['tab' => 'payments'];
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_screen')->justReturn((object) ['id' => 'woocommerce_page_wc-settings']);
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\expect('get_option')->never();
        ob_start();
        try {
            (new Notices())->maybeShowMissingConfig();
            self::assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
            $_GET = [];
        }
    }

    public function testLeafletShipsReadableSourceAndRequiredLocalImages(): void
    {
        $root = dirname(__DIR__) . '/assets/lib/leaflet/';
        self::assertStringContainsString('Leaflet 1.9.4', file_get_contents($root . 'leaflet-src.js'));
        self::assertStringContainsString('Redistribution and use', file_get_contents($root . 'LICENSE'));
        foreach (['marker-icon.png', 'marker-icon-2x.png', 'marker-shadow.png', 'layers.png', 'layers-2x.png'] as $image) {
            self::assertFileExists($root . 'images/' . $image);
        }
        self::assertStringNotContainsString('unpkg.com', file_get_contents(dirname(__DIR__) . '/assets/js/checkout-delivery.js'));
        $map = json_decode(file_get_contents($root . 'leaflet-src.js.map'), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($map['sourcesContent']);
    }
}
