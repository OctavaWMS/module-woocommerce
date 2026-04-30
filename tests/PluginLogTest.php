<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\PluginLog;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class PluginLogTest extends TestCase
{
    public function testRedactConnectRequestBodyMasksEmail(): void
    {
        $out = PluginLog::redactConnectRequestBody([
            'siteUrl' => 'https://shop.example',
            'adminEmail' => 'merchant@shop.example',
            'storeName' => 'Shop',
        ]);
        self::assertSame('https://shop.example', $out['siteUrl']);
        self::assertSame('m***@shop.example', $out['adminEmail']);
        self::assertSame('Shop', $out['storeName']);
    }

    public function testTruncateAppendsNoteWhenLong(): void
    {
        $long = str_repeat('a', 5000);
        $t = PluginLog::truncate($long, 100);
        self::assertStringEndsWith('…[truncated]', $t);
        self::assertSame(100 + mb_strlen('…[truncated]'), mb_strlen($t));
    }

    public function testRedactOutgoingRequestHeadersMasksAuthorization(): void
    {
        $b64 = base64_encode('ck_x:cs_y');
        $masked = mb_substr($b64, 0, 3) . '…' . mb_substr($b64, -5);
        $h = PluginLog::redactOutgoingRequestHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . $b64,
        ]);
        self::assertSame('application/json', $h['Accept']);
        self::assertSame('Basic ' . $masked, $h['Authorization']);
    }

    public function testUserMessageFromApiJsonUsesDetailForProblemBodies(): void
    {
        $m = PluginLog::userMessageFromApiJson([
            'type' => 'http://example/problem',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'Token expired',
        ], 'fallback');
        self::assertSame('Token expired', $m);
    }

    public function testUserMessageFromApiJsonFallsBackWhenDetailEmptyUsesTitle(): void
    {
        $m = PluginLog::userMessageFromApiJson([
            'title' => 'Not Found',
            'detail' => '   ',
        ], 'fallback');
        self::assertSame('Not Found', $m);
    }

    public function testRedactApiResponseDataForLogRedactsNestedSecrets(): void
    {
        $data = [
            'message' => 'fail',
            'apiKey' => 'secret-key',
            'nested' => ['access_token' => 'tok', 'ok' => true],
        ];
        $out = PluginLog::redactApiResponseDataForLog($data);
        self::assertIsArray($out);
        self::assertSame('sec…t-key', $out['apiKey'] ?? null);
        self::assertSame('***tok', is_array($out['nested'] ?? null) ? $out['nested']['access_token'] : null);
        self::assertTrue((bool) (is_array($out['nested'] ?? null) ? $out['nested']['ok'] : false));
    }

    public function testLogUsesUnifiedSourceAndDayPrefix(): void
    {
        $spy = new class () {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $entries = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->entries[] = [$level, $message, $context];
            }
        };

        Functions\when('wc_get_logger')->justReturn($spy);
        Functions\when('wp_json_encode')->alias('json_encode');

        PluginLog::log('error', 'api_probe', ['a' => 1]);

        self::assertCount(1, $spy->entries);
        self::assertSame('error', $spy->entries[0][0]);
        self::assertStringContainsString('octavawms_', $spy->entries[0][1]);
        self::assertStringContainsString('api_probe', $spy->entries[0][1]);
        self::assertSame(PluginLog::SOURCE, $spy->entries[0][2]['source'] ?? '');
        $decoded = json_decode(explode(' | ', $spy->entries[0][1], 2)[1] ?? '{}', true);
        self::assertSame(1, is_array($decoded) ? ($decoded['a'] ?? null) : null);
    }

    public function testHttpExchangeBuildsNestedRequestResponse(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn ($r) => (int) ($r['response']['code'] ?? 0));
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => (string) ($r['body'] ?? ''));
        $flatHeaders = new \ArrayObject(['Content-Type' => 'application/json']);
        Functions\when('wp_remote_retrieve_headers')->justReturn($flatHeaders);

        $wpResponse = [
            'response' => ['code' => 500],
            'body' => '{"status":"error","message":"fail"}',
            'headers' => $flatHeaders,
        ];

        $ex = PluginLog::httpExchange(
            'POST',
            'https://alpha.example/connect',
            ['Accept' => 'application/json', 'Authorization' => 'Bearer x'],
            '{"siteUrl":"https://shop.test"}',
            $wpResponse
        );

        self::assertSame('POST', $ex['request']['method']);
        self::assertArrayHasKey('response', $ex);
        self::assertSame(500, $ex['response']['http_status'] ?? 0);
        self::assertIsArray($ex['response']['json'] ?? null);
        self::assertSame('error', is_array($ex['response']['json'] ?? null) ? ($ex['response']['json']['status'] ?? '') : '');
        self::assertSame('Bearer ***x', $ex['request']['headers']['Authorization'] ?? '');
    }
}
