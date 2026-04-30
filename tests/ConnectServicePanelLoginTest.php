<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\ConnectService;
use OctavaWMS\WooCommerce\Options;

final class ConnectServicePanelLoginTest extends TestCase
{
    public function testHandleAjaxPanelLoginUrlEncodesRefreshTokenInLoginUrl(): void
    {
        $_POST['security'] = 'dummy';

        Functions\when('__')->returnArg(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('apply_filters')->alias(static function (string $hook, $value, ...$args) {
            return $value;
        });

        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'refresh_token' => 'a+b',
                    'api_key' => '',
                    'label_endpoint' => 'https://h.example/apps/woocommerce/api/label',
                ];
            }
            if ($name === Options::LEGACY_API_KEY) {
                return '';
            }

            return $default;
        });

        Functions\when('wp_remote_request')->alias(static function (): void {
            throw new \RuntimeException('wp_remote_request should not run when refresh_token is stored');
        });

        $captured = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(static function ($data) use (&$captured): void {
                $captured = $data;
                throw new \RuntimeException('json_ok');
            });

        $svc = new ConnectService();

        try {
            $svc->handleAjaxPanelLoginUrl();
        } catch (\RuntimeException $e) {
            self::assertSame('json_ok', $e->getMessage());
        }

        self::assertIsArray($captured);
        self::assertArrayHasKey('loginUrl', $captured);
        $loginUrl = $captured['loginUrl'] ?? null;
        self::assertIsString($loginUrl);
        self::assertSame('https://app.izparti.bg/#/login?refreshToken=a%2Bb', $loginUrl);
    }
}
