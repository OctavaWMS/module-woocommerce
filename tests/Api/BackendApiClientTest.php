<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Api;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\PluginLog;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class BackendApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return (int) ($response['response']['code'] ?? 0);
        });
        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return (string) ($response['body'] ?? '');
        });
        Functions\when('wp_remote_retrieve_response_header')->alias(static function ($response, $header = '') {
            $h = strtolower((string) $header);
            $headers = $response['headers'] ?? [];

            return (string) ($headers[$h] ?? '');
        });
        Functions\when('wp_remote_retrieve_headers')->alias(static function ($response) {
            if (! is_array($response)) {
                return new \ArrayObject([]);
            }
            /** @var array<string, mixed> */
            $hdr = $response['headers'] ?? [];

            return new \ArrayObject(is_array($hdr) ? $hdr : []);
        });
        Functions\when('wc_get_logger')->alias(static function () {
            return new class () {
                public function log(string $_level = '', string $_message = '', array $_ctx = []): void {}
            };
        });
    }

    public function testGetBaseUrlUsesLegacyLabelEndpointHost(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['label_endpoint' => 'https://self-hosted.example.com/apps/woocommerce/api/label'];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        $client = new BackendApiClient();
        self::assertSame('https://self-hosted.example.com', $client->getBaseUrl());
    }

    public function testGetBaseUrlFallsBackToDefaultApiBaseWhenNotConfigured(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }

            return $default;
        });

        $client = new BackendApiClient();
        self::assertSame(Options::DEFAULT_API_BASE, $client->getBaseUrl());
    }

    public function testFindPreprocessingTasksForShipmentExtractsTaskAndQueueIds(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['api_key' => 'token'];
            }

            return $default;
        });

        Functions\when('wp_remote_request')->alias(static function (string $url) {
            self::assertStringContainsString('/api/delivery-services/delivery-request-service', $url);
            self::assertStringContainsString('action=tasks', $url);

            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    ['task' => ['id' => 501], 'queue' => ['queue' => ['id' => 99]]],
                ], JSON_THROW_ON_ERROR),
            ];
        });

        $client = new BackendApiClient();
        $out = $client->findPreprocessingTasksForShipment(12345);
        self::assertTrue($out['ok']);
        self::assertSame(501, $out['task_id']);
        self::assertSame(99, $out['queue_id']);
    }

    public function testCreateOrUpdatePreprocessingTaskReturnsPdfWhenContentTypePdf(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['api_key' => 'token'];
            }

            return $default;
        });
        Functions\when('wp_json_encode')->alias('json_encode');

        Functions\when('wp_remote_request')->alias(static function (string $url, array $args = []) {
            self::assertStringEndsWith('/api/delivery-services/preprocessing-task', $url);
            self::assertSame('POST', $args['method']);

            return [
                'response' => ['code' => 200],
                'body' => '%PDF-1.4',
                'headers' => ['content-type' => 'application/pdf'],
            ];
        });

        $client = new BackendApiClient();
        $r = $client->createOrUpdatePreprocessingTask(null, ['state' => 'measured']);
        self::assertTrue($r['ok']);
        self::assertSame('%PDF-1.4', $r['pdf']);
        self::assertSame(null, $r['task_id']);
    }

    public function testCreateOrUpdatePreprocessingTaskReturnsTaskIdFromJsonBody(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['api_key' => 'token'];
            }

            return $default;
        });
        Functions\when('wp_json_encode')->alias('json_encode');

        Functions\when('wp_remote_request')->alias(static function (string $url) {
            self::assertStringContainsString('/api/delivery-services/preprocessing-task/77', $url);

            return [
                'response' => ['code' => 200],
                'body' => '{"id":77,"state":"packed"}',
                'headers' => ['content-type' => 'application/json'],
            ];
        });

        $client = new BackendApiClient();
        $r = $client->createOrUpdatePreprocessingTask(77, ['state' => 'measured']);
        self::assertTrue($r['ok']);
        self::assertNull($r['pdf']);
        self::assertSame(77, $r['task_id']);
    }

    public function testDownloadPreprocessingTaskLabelReadyWhenPdfReturned(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['api_key' => 'token'];
            }

            return $default;
        });

        Functions\when('wp_remote_request')->alias(static function (string $url) {
            self::assertStringContainsString('/api/delivery-services/preprocessing-task/501', $url);

            return [
                'response' => ['code' => 200],
                'body' => '%PDF-bytes',
                'headers' => ['content-type' => 'application/pdf'],
            ];
        });

        $client = new BackendApiClient();
        $r = $client->downloadPreprocessingTaskLabel(501);
        self::assertTrue($r['ok']);
        self::assertTrue($r['ready']);
        self::assertSame('%PDF-bytes', $r['pdf']);
    }

    public function testFindOrderByExtIdReturnsNullOnEmptyEmbedded(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }
            if ($name === Options::LEGACY_API_KEY) {
                return '';
            }

            return $default;
        });

        Functions\expect('wp_remote_request')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body' => json_encode(['_embedded' => ['order' => []]], JSON_THROW_ON_ERROR),
            ]);

        $client = new BackendApiClient();
        self::assertNull($client->findOrderByExtId('wc-123'));
    }

    public function testFindOrderByExtIdReturnsFirstOrder(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return [];
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return '';
            }
            if ($name === Options::LEGACY_API_KEY) {
                return '';
            }

            return $default;
        });

        Functions\expect('wp_remote_request')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body' => json_encode(['_embedded' => ['order' => [['id' => 99, 'extId' => 'abc']]]], JSON_THROW_ON_ERROR),
            ]);

        $client = new BackendApiClient();
        $order = $client->findOrderByExtId('abc');
        self::assertIsArray($order);
        self::assertSame(99, $order['id']);
    }

    public function testImportOrderFailsWhenSourceIdZero(): void
    {
        $client = new BackendApiClient();
        $result = $client->importOrder('ext-1', 0);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('source', strtolower((string) ($result['message'] ?? '')));
    }

    public function testImportOrderBuildsCorrectPayload(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['api_key' => 'secret'];
            }

            return $default;
        });

        $captured = null;
        Functions\when('wp_remote_request')->alias(static function (string $url, array $args = []) use (&$captured) {
            $captured = ['url' => $url, 'args' => $args];

            return [
                'response' => ['code' => 200],
                'body' => '{"ok":true}',
            ];
        });

        $client = new BackendApiClient();
        $result = $client->importOrder('order-key-1', 7);
        self::assertTrue($result['ok']);
        self::assertIsArray($captured);
        self::assertStringContainsString('/api/integrations/import', (string) $captured['url']);
        $data = json_decode((string) ($captured['args']['body'] ?? ''), true);
        self::assertIsArray($data);
        self::assertSame(7, $data['source']);
        self::assertSame('order-key-1', $data['sourceData']['filters']['extId']);
    }

    public function testImportOrderFailureLogsAndReturnsStatusAndExcerpt(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['api_key' => 'secret'];
            }

            return $default;
        });

        Functions\when('wp_json_encode')->alias('json_encode');

        $spy = new class () {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $entries = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->entries[] = [$level, $message, $context];
            }
        };
        Functions\when('wc_get_logger')->justReturn($spy);

        Functions\when('wp_remote_request')->alias(static function () {
            return [
                'response' => ['code' => 422],
                'body' => json_encode([
                    'errorMessage' => 'bad import',
                    'apiKey' => 'never-log-raw',
                ], JSON_THROW_ON_ERROR),
            ];
        });

        $client = new BackendApiClient();
        $result = $client->importOrder('ext-xyz', 5);

        self::assertFalse($result['ok']);
        self::assertSame(422, $result['status'] ?? null);
        self::assertArrayHasKey('raw_excerpt', $result);
        self::assertSame('bad import', $result['message'] ?? null);
        self::assertCount(1, $spy->entries);
        self::assertSame('error', $spy->entries[0][0]);
        $parts = explode(' | ', $spy->entries[0][1], 2);
        self::assertStringContainsString('import', $parts[0]);
        self::assertStringContainsString('octavawms_', $parts[0]);
        self::assertCount(2, $parts);
        $ctx = json_decode($parts[1], true);
        self::assertIsArray($ctx);
        self::assertSame('ext-xyz', $ctx['ext_id'] ?? null);
        self::assertSame(5, $ctx['source_id'] ?? null);
        self::assertIsArray($ctx['request'] ?? null);
        self::assertIsArray($ctx['response'] ?? null);
        self::assertSame(422, $ctx['response']['http_status'] ?? null);
        self::assertStringContainsString('/api/integrations/import', (string) ($ctx['request']['url'] ?? ''));
        self::assertTrue((bool) ($ctx['bearer_token_configured'] ?? false));
        $rj = $ctx['response']['json'] ?? null;
        self::assertIsArray($rj);
        self::assertSame('nev…g-raw', $rj['apiKey'] ?? '');
        self::assertSame(PluginLog::SOURCE, $spy->entries[0][2]['source'] ?? '');
    }

    public function testImportOrderFailureUsesDetailFromProblemJson(): void
    {
        Functions\when('get_option')->alias(static function (string $name, $default = false) {
            if ($name === 'woocommerce_octavawms_settings') {
                return []; // simulate missing API key
            }

            return $default;
        });

        Functions\when('wc_get_logger')->justReturn(new class () {
            public function log(string $_level, string $_message, array $_ctx = []): void {}
        });
        Functions\when('wp_json_encode')->alias('json_encode');

        Functions\when('wp_remote_request')->alias(static function () {
            return [
                'response' => ['code' => 401],
                'body' => '{"type":"http://example/problem","title":"Unauthorized","status":401,"detail":"Invalid or missing Bearer token"}',
            ];
        });

        $client = new BackendApiClient();
        $result = $client->importOrder('ext-401', 3);

        self::assertFalse($result['ok']);
        self::assertSame(401, $result['status'] ?? null);
        self::assertSame('Invalid or missing Bearer token', $result['message'] ?? null);
    }

    public function testRefreshBearerTokenReturnsTrueOnSuccessfulConnect(): void
    {
        $integrationSettings = [
            'label_endpoint' => '',
            'api_key' => '',
        ];

        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$integrationSettings) {
            if ($name === 'woocommerce_octavawms_settings') {
                return $integrationSettings;
            }
            if ($name === 'admin_email') {
                return 'owner@example.com';
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return $integrationSettings['label_endpoint'] ?? '';
            }
            if ($name === Options::LEGACY_API_KEY) {
                return $integrationSettings['api_key'] ?? '';
            }

            return $default;
        });

        Functions\when('update_option')->alias(static function (string $name, $value) use (&$integrationSettings) {
            if ($name === 'woocommerce_octavawms_settings') {
                $integrationSettings = array_merge($integrationSettings, (array) $value);

                return true;
            }
            if ($name === Options::LEGACY_API_KEY) {
                $integrationSettings['api_key'] = (string) $value;

                return true;
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                $integrationSettings['label_endpoint'] = (string) $value;

                return true;
            }

            return true;
        });

        Functions\when('home_url')->justReturn('https://wc-store.example');
        Functions\when('get_bloginfo')->justReturn('WC Store');

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturnUsing(function (string $url, array $args = []) {
                self::assertSame(Options::DEFAULT_API_BASE . '/apps/woocommerce/connect', $url);
                $body = json_decode((string) ($args['body'] ?? ''), true);
                self::assertIsArray($body);
                self::assertSame('https://wc-store.example', $body['siteUrl'] ?? '');
                self::assertSame('owner@example.com', $body['adminEmail'] ?? '');
                self::assertSame('WC Store', $body['storeName'] ?? '');

                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(
                        [
                            'status' => 'ok',
                            'apiKey' => 'new-plugin-key',
                            'labelEndpoint' => Options::DEFAULT_API_BASE . '/apps/woocommerce/api/label',
                            'sourceId' => 42,
                        ],
                        JSON_THROW_ON_ERROR
                    ),
                ];
            });

        $client = new BackendApiClient();
        self::assertTrue($client->refreshBearerToken());
        self::assertSame('new-plugin-key', $integrationSettings['api_key']);
        self::assertSame(Options::DEFAULT_API_BASE . '/apps/woocommerce/api/label', $integrationSettings['label_endpoint']);
        self::assertSame('42', $integrationSettings['source_id']);
    }

    public function testRefreshBearerTokenConnectReturnsRefreshThenExchangesOAuth(): void
    {
        $integrationSettings = [
            'label_endpoint' => '',
            'api_key' => '',
        ];

        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$integrationSettings) {
            if ($name === 'woocommerce_octavawms_settings') {
                return $integrationSettings;
            }
            if ($name === 'admin_email') {
                return 'owner@example.com';
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return $integrationSettings['label_endpoint'] ?? '';
            }
            if ($name === Options::LEGACY_API_KEY) {
                return $integrationSettings['api_key'] ?? '';
            }

            return $default;
        });

        Functions\when('update_option')->alias(static function (string $name, $value) use (&$integrationSettings) {
            if ($name === 'woocommerce_octavawms_settings') {
                $integrationSettings = array_merge($integrationSettings, (array) $value);

                return true;
            }
            if ($name === Options::LEGACY_API_KEY) {
                $integrationSettings['api_key'] = (string) $value;

                return true;
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                $integrationSettings['label_endpoint'] = (string) $value;

                return true;
            }

            return true;
        });

        Functions\when('home_url')->justReturn('https://wc-store.example');
        Functions\when('get_bloginfo')->justReturn('WC Store');

        $n = 0;
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args = []) use (&$n): array {
            ++$n;
            if (str_contains($url, '/apps/woocommerce/connect')) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(
                        [
                            'status' => 'ok',
                            'refreshToken' => 'rt-test',
                            'domain' => 'izpratibg',
                            'sourceId' => 13259,
                        ],
                        JSON_THROW_ON_ERROR
                    ),
                ];
            }
            if (str_contains($url, '/oauth')) {
                $body = json_decode((string) ($args['body'] ?? ''), true);
                self::assertIsArray($body);
                self::assertSame('refresh_token', $body['grant_type'] ?? '');
                self::assertSame('orderadmin', $body['client_id'] ?? '');
                self::assertSame('rt-test', $body['refresh_token'] ?? '');
                self::assertSame('izpratibg', $body['domain'] ?? '');

                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['access_token' => 'jwt-access-1'], JSON_THROW_ON_ERROR),
                ];
            }

            self::fail('Unexpected POST URL: ' . $url);
        });

        $client = new BackendApiClient();
        self::assertTrue($client->refreshBearerToken());
        self::assertSame('jwt-access-1', $integrationSettings['api_key']);
        self::assertSame('rt-test', $integrationSettings['refresh_token'] ?? null);
        self::assertSame('izpratibg', $integrationSettings['oauth_domain'] ?? null);
        self::assertSame('13259', $integrationSettings['source_id'] ?? null);
        self::assertSame(2, $n);
    }

    public function testRequestRetriesAfter401(): void
    {
        // Non-empty key skips lazy-init refresh; 401 path triggers a single connect refresh.
        $integrationSettings = [
            'label_endpoint' => '',
            'api_key' => 'stale-expired-key',
        ];

        Functions\when('get_option')->alias(static function (string $name, $default = false) use (&$integrationSettings) {
            if ($name === 'woocommerce_octavawms_settings') {
                return $integrationSettings;
            }
            if ($name === 'admin_email') {
                return 'owner@example.com';
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                return $integrationSettings['label_endpoint'] ?? '';
            }
            if ($name === Options::LEGACY_API_KEY) {
                return $integrationSettings['api_key'] ?? '';
            }

            return $default;
        });

        Functions\when('update_option')->alias(static function (string $name, $value) use (&$integrationSettings) {
            if ($name === 'woocommerce_octavawms_settings') {
                $integrationSettings = array_merge($integrationSettings, (array) $value);

                return true;
            }
            if ($name === Options::LEGACY_API_KEY) {
                $integrationSettings['api_key'] = (string) $value;

                return true;
            }
            if ($name === Options::LEGACY_LABEL_ENDPOINT) {
                $integrationSettings['label_endpoint'] = (string) $value;

                return true;
            }

            return true;
        });

        Functions\when('home_url')->justReturn('https://wc-store.example');
        Functions\when('get_bloginfo')->justReturn('WC Store');

        Functions\expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 200],
                'body' => json_encode(
                    [
                        'status' => 'ok',
                        'apiKey' => 'refreshed-key',
                        'labelEndpoint' => Options::DEFAULT_API_BASE . '/apps/woocommerce/api/label',
                        'sourceId' => 1,
                    ],
                    JSON_THROW_ON_ERROR
                ),
            ]);

        $requestCount = 0;
        Functions\when('wp_remote_request')->alias(static function () use (&$requestCount) {
            ++$requestCount;
            if ($requestCount === 1) {
                return [
                    'response' => ['code' => 401],
                    'body' => '{"error":"unauthorized"}',
                ];
            }

            return [
                'response' => ['code' => 200],
                'body' => '{"ok":true,"afterRefresh":true}',
            ];
        });

        $client = new BackendApiClient();
        $result = $client->request('GET', '/api/products/order?page=1');
        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status']);
        self::assertIsArray($result['data']);
        self::assertTrue((bool) ($result['data']['afterRefresh'] ?? false));
        self::assertSame(2, $requestCount);
        self::assertSame('refreshed-key', $integrationSettings['api_key']);
    }
}
