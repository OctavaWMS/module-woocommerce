<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\WooRestCredentials;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class WooRestCredentialsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testFindOctavawmsKeyReturnsNullWhenNoWpdb(): void
    {
        unset($GLOBALS['wpdb']);
        self::assertNull(WooRestCredentials::findOctavawmsKey());
    }

    public function testFindOctavawmsKeyReturnsRow(): void
    {
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            public function prepare(string $query, ...$args): string
            {
                return $query . ' -- ' . implode(',', $args);
            }

            public function get_row(string $sql, string $output): ?array
            {
                return [
                    'consumer_secret' => 'cs_fa3ed9cdadae57935b87aa3011e77a8c90feaaf4',
                    'truncated_key' => 'fc2aadf',
                    'description' => 'OctavaWMS - API (2026-04-26 23:15:01)',
                    'user_id' => 5,
                ];
            }
        };

        $out = WooRestCredentials::findOctavawmsKey();
        self::assertIsArray($out);
        self::assertSame('fc2aadf', $out['key_last7']);
        self::assertSame('cs_fa3ed9cdadae57935b87aa3011e77a8c90feaaf4', $out['consumer_secret']);
        self::assertSame(5, $out['user_id']);
    }

    public function testSignConnectRequestProducesExpectedShape(): void
    {
        $creds = ['consumer_secret' => 'cs_secret', 'key_last7' => 'fc2aadf'];
        $body = '{"siteUrl":"https://shop.test"}';
        $signed = WooRestCredentials::signConnectRequest($creds, $body);

        self::assertStringContainsString('OctavaWMS key_last7=fc2aadf', $signed['header']);
        self::assertStringContainsString('algo=HMAC-SHA256', $signed['header']);
        self::assertStringContainsString('signature=' . $signed['signature'], $signed['header']);

        $expected = base64_encode(hash_hmac('sha256', $signed['ts'] . '.' . $signed['nonce'] . '.' . $body, 'cs_secret', true));
        self::assertSame($expected, $signed['signature']);
    }
}
