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
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return (int) ($response['response']['code'] ?? 0);
        });
        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return (string) ($response['body'] ?? '');
        });
        Functions\when('wp_remote_retrieve_headers')->alias(static function ($response) {
            $hdr = is_array($response) ? ($response['headers'] ?? []) : [];

            return new \ArrayObject(is_array($hdr) ? $hdr : []);
        });

        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [
                    'refresh_token' => 'ignored-stale',
                    'api_key' => 'bearer-present',
                    'label_endpoint' => 'https://h.example/apps/woocommerce/api/label',
                ];
            }
            if ($name === Options::LEGACY_API_KEY) {
                return '';
            }

            return $default;
        });

        Functions\when('wp_remote_request')->alias(static function (string $url, array $args = []) {
            if (str_contains($url, '/api/users/users/0')) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['id' => 7], JSON_THROW_ON_ERROR),
                ];
            }
            if (str_contains($url, '/api/users/authenticate/7')) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['refreshToken' => 'a+b'], JSON_THROW_ON_ERROR),
                ];
            }

            return ['response' => ['code' => 500], 'body' => '{}'];
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
        self::assertSame('https://app.izprati.bg/#/login?refreshToken=a%2Bb', $loginUrl);
    }
}
