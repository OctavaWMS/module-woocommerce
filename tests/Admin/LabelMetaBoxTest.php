<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Admin;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class LabelMetaBoxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('__')->returnArg(1);
    }

    public function testBuildGenerateLabelUrlContainsActionAndNonce(): void
    {
        Functions\when('admin_url')->alias(static function (string $path = '', string $scheme = 'admin') {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        });
        Functions\when('wp_create_nonce')->justReturn('testnonce');
        Functions\when('wp_nonce_url')->alias(static function (string $url, string|int $action = -1, string $name = '_wpnonce') {
            return $url . '&' . $name . '=testnonce';
        });

        $box = new LabelMetaBox();
        $url = $box->buildGenerateLabelUrl(42);

        self::assertStringContainsString('action=octavawms_generate_label', $url);
        self::assertStringContainsString('order_id=42', $url);
        self::assertStringContainsString('_wpnonce=testnonce', $url);
    }

    public function testBuildDownloadMarkupExternalUrl(): void
    {
        $box = new LabelMetaBox();
        $html = $box->buildDownloadMarkup(1, '', 'https://labels.example/1.pdf', 'button');
        self::assertStringContainsString('href="https://labels.example/1.pdf"', $html);
        self::assertStringContainsString('class="button"', $html);
    }
}
