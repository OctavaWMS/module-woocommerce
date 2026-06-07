<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Checkout;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Checkout\CheckoutCalculator;
use OctavaWMS\WooCommerce\Checkout\ShippingMethod;
use OctavaWMS\WooCommerce\PluginLog;
use PHPUnit\Framework\Assert;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class CheckoutCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg();
        Functions\when('wc_get_logger')->justReturn(new class () {
            public function log(string $level, string $message, array $context = []): void
            {
                unset($level, $message, $context);
            }
        });
        Functions\when('get_option')->alias(static function (string $name, mixed $default = false): mixed {
            if ($name === 'woocommerce_octavawms_settings') {
                return ['source_id' => '123'];
            }
            if ($name === 'woocommerce_weight_unit') {
                return 'kg';
            }

            return $default;
        });
    }

    public function testBuildsCalculatorPayloadFromWooPackageAndNormalizesRates(): void
    {
        $api = new class () extends BackendApiClient {
            /** @var array<string, mixed>|null */
            public ?array $lastBody = null;

            public function getIntegrationSource(int $sourceId): ?array
            {
                Assert::assertSame(123, $sourceId);

                return [
                    'settings' => [
                        'DeliveryServices' => [
                            'options' => [
                                'sender' => 77,
                                'carrierMapping' => [
                                    ['deliveryService' => 5],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            public function fetchLocalitiesByPostcode(string $postcode, int $page = 1): array
            {
                Assert::assertSame('9000', $postcode);
                Assert::assertSame(1, $page);

                return [
                    'items' => [[
                        'id' => 900,
                        'name' => 'Varna',
                        'postcode' => '9000',
                        'country' => ['code' => 'BG'],
                    ]],
                    'total_pages' => 1,
                ];
            }

            public function request(string $method, string $path, ?array $jsonBody = null, bool $retried = false): array
            {
                unset($retried);
                Assert::assertSame('POST', $method);
                Assert::assertSame('/api/delivery-services/calculator', $path);
                $this->lastBody = $jsonBody;

                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => [
                        'rates' => [
                            [
                                'id' => 12,
                                'name' => 'СТАНДАРТ 24 ЧАСА',
                                'type' => 'service_point',
                                'deliveryPrice' => 4.5,
                                'deliveryService' => ['id' => 5, 'name' => 'Speedy'],
                            ],
                            [
                                'id' => 13,
                                'name' => 'СТАНДАРТ 24 ЧАСА',
                                'type' => 'self_service_point',
                                'deliveryPrice' => 3.5,
                                'deliveryService' => ['id' => 5, 'name' => 'Speedy'],
                            ],
                            [
                                'id' => 14,
                                'name' => 'АДРЕС',
                                'type' => 'address',
                                'deliveryPrice' => 5.5,
                                'deliveryService' => ['id' => 5, 'name' => 'Speedy'],
                            ],
                        ],
                        'deliveryServices' => [
                            ['id' => 5, 'name' => 'Speedy', 'logo' => 'https://cdn.test/speedy.png'],
                        ],
                    ],
                    'raw' => '',
                    'response_headers' => [],
                    'request' => ['method' => 'POST', 'url' => '', 'headers' => [], 'body' => []],
                ];
            }
        };

        $product = new class () {
            public function get_weight(): string
            {
                return '1.25';
            }
        };

        $calculator = new CheckoutCalculator($api);
        $rates = $calculator->calculatePackage([
            'destination' => [
                'city' => 'Varna',
                'postcode' => '9000',
                'country' => 'BG',
                'address' => '1 Main',
            ],
            'contents_cost' => '99.95',
            'contents' => [
                ['data' => $product, 'quantity' => 2],
            ],
        ]);

        self::assertSame([
            'debug' => true,
            'clearCache' => true,
            'sender' => 77,
            'to' => ['postcode' => '9000', 'country' => 'BG'],
            'weight' => 2500.0,
            'estimatedCost' => 99.95,
            'payment' => 0,
            'timeout' => 30,
            'deliveryServices' => [5],
        ], $api->lastBody);

        self::assertCount(3, $rates);
        self::assertStringStartsWith(ShippingMethod::METHOD_ID . ':5:12:', (string) $rates[0]['optionId']);
        self::assertSame('office', $rates[0]['methodKind']);
        self::assertSame('Speedy - ДО ОФИС', $rates[0]['title']);
        self::assertSame(5, $rates[0]['deliveryService']);
        self::assertSame(12, $rates[0]['rate']);
        self::assertSame(4.5, $rates[0]['cost']);
        self::assertSame('https://cdn.test/speedy.png', $rates[0]['carrierLogo']);
        self::assertSame([], $rates[0]['servicePoints']);
        self::assertSame('locker', $rates[1]['methodKind']);
        self::assertSame('Speedy - ДО АВТОМАТ', $rates[1]['title']);
        self::assertSame([], $rates[1]['servicePoints']);
        self::assertSame('address', $rates[2]['methodKind']);
        self::assertSame('Speedy - ДО АДРЕС', $rates[2]['title']);
        self::assertSame(900, \OctavaWMS\WooCommerce\Checkout\CheckoutSession::context()['localityId'] ?? null);
    }

    public function testAutoSetsSenderWhenSourceHasNoneAndAccountHasOne(): void
    {
        $logged = [];
        Functions\when('wc_get_logger')->justReturn(new class ($logged) {
            /** @var list<array{level: string, message: string}> */
            public array $entries;

            public function __construct(array &$logged)
            {
                $this->entries = &$logged;
            }

            public function log(string $level, string $message, array $context = []): void
            {
                unset($context);
                $this->entries[] = ['level' => $level, 'message' => $message];
            }
        });

        $api = new class () extends BackendApiClient {
            public function getIntegrationSource(int $sourceId): ?array
            {
                Assert::assertSame(123, $sourceId);

                return [
                    'settings' => [
                        'DeliveryServices' => [
                            'options' => [
                                'sender' => 0,
                            ],
                        ],
                    ],
                ];
            }

            public function fetchActiveSendersPreview(int $limit = 2): array
            {
                Assert::assertSame(2, $limit);

                return [
                    'items' => [['id' => 19227, 'name' => 'Iron Logic', 'state' => 'active']],
                    'has_more' => false,
                    'ok' => true,
                    'message' => '',
                    'diagnostics' => [],
                ];
            }

            public function fetchLocalitiesByPostcode(string $postcode, int $page = 1): array
            {
                unset($postcode, $page);

                return ['items' => [], 'total_pages' => 1];
            }

            public function patchIntegrationSource(int $sourceId, array $body): array
            {
                Assert::assertSame(123, $sourceId);
                Assert::assertSame(19227, $body['settings']['DeliveryServices']['options']['sender'] ?? null);

                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => [],
                    'raw' => '',
                    'response_headers' => [],
                ];
            }

            public function request(string $method, string $path, ?array $jsonBody = null, bool $retried = false): array
            {
                unset($retried);
                Assert::assertSame('POST', $method);
                Assert::assertSame('/api/delivery-services/calculator', $path);
                Assert::assertSame(19227, $jsonBody['sender'] ?? null);

                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => ['rates' => []],
                    'raw' => '{"rates":[]}',
                    'response_headers' => [],
                    'request' => ['method' => 'POST', 'url' => '', 'headers' => [], 'body' => []],
                ];
            }
        };

        $calculator = new CheckoutCalculator($api);
        self::assertSame([], $calculator->calculatePackage([
            'destination' => ['city' => 'Varna', 'country' => 'BG', 'postcode' => '9000'],
        ]));
        self::assertTrue(
            (bool) array_filter(
                $logged,
                static fn (array $entry): bool => str_contains($entry['message'], '"reason":"auto_set_sender"')
            )
        );
    }

    public function testLogsCalculatorRequestAndResponse(): void
    {
        $logged = [];
        Functions\when('wc_get_logger')->justReturn(new class ($logged) {
            /** @var list<array{level: string, message: string}> */
            public array $entries;

            public function __construct(array &$logged)
            {
                $this->entries = &$logged;
            }

            public function log(string $level, string $message, array $context = []): void
            {
                unset($context);
                $this->entries[] = ['level' => $level, 'message' => $message];
            }
        });

        $api = new class () extends BackendApiClient {
            public function getIntegrationSource(int $sourceId): ?array
            {
                unset($sourceId);

                return [
                    'settings' => [
                        'DeliveryServices' => [
                            'options' => ['sender' => 77],
                        ],
                    ],
                ];
            }

            public function fetchLocalitiesByPostcode(string $postcode, int $page = 1): array
            {
                unset($postcode, $page);

                return ['items' => [], 'total_pages' => 1];
            }

            public function request(string $method, string $path, ?array $jsonBody = null, bool $retried = false): array
            {
                unset($retried);

                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => ['rates' => []],
                    'raw' => '{"rates":[]}',
                    'response_headers' => ['content-type' => 'application/json'],
                    'request' => PluginLog::requestFromOutbound($method, 'https://api.test' . $path, [], $jsonBody),
                ];
            }
        };

        $calculator = new CheckoutCalculator($api);
        self::assertSame([], $calculator->calculatePackage([
            'destination' => ['city' => 'Varna', 'country' => 'BG', 'postcode' => '9000'],
        ]));
        self::assertCount(1, $logged);
        self::assertSame('warning', $logged[0]['level']);
        self::assertStringContainsString('"reason":"no_rates"', $logged[0]['message']);
        self::assertStringContainsString('/api/delivery-services/calculator', $logged[0]['message']);
        self::assertStringContainsString('"debug_enabled":true', $logged[0]['message']);
        self::assertStringContainsString('"clear_cache":true', $logged[0]['message']);
        self::assertStringContainsString('"debug":true', $logged[0]['message']);
        self::assertStringContainsString('"clearCache":true', $logged[0]['message']);
        self::assertStringContainsString('"sender":77', $logged[0]['message']);
        self::assertStringContainsString('"response":{"http_status":200', $logged[0]['message']);
    }

    public function testUsesSmallFallbackWeightWhenProductsHaveNoWeight(): void
    {
        $api = new class () extends BackendApiClient {
            /** @var array<string, mixed>|null */
            public ?array $lastBody = null;

            public function getIntegrationSource(int $sourceId): ?array
            {
                unset($sourceId);

                return [
                    'settings' => [
                        'DeliveryServices' => [
                            'options' => ['sender' => 77],
                        ],
                    ],
                ];
            }

            public function fetchLocalitiesByPostcode(string $postcode, int $page = 1): array
            {
                unset($postcode, $page);

                return ['items' => [], 'total_pages' => 1];
            }

            public function request(string $method, string $path, ?array $jsonBody = null, bool $retried = false): array
            {
                unset($method, $path, $retried);
                $this->lastBody = $jsonBody;

                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => ['rates' => []],
                    'raw' => '{"rates":[]}',
                    'response_headers' => [],
                    'request' => ['method' => 'POST', 'url' => '', 'headers' => [], 'body' => []],
                ];
            }
        };

        $product = new class () {
            public function get_weight(): string
            {
                return '';
            }
        };

        $calculator = new CheckoutCalculator($api);
        self::assertSame([], $calculator->calculatePackage([
            'destination' => ['country' => 'BG', 'postcode' => '9002'],
            'contents' => [
                ['data' => $product, 'quantity' => 1],
            ],
        ]));

        self::assertTrue($api->lastBody['debug'] ?? null);
        self::assertTrue($api->lastBody['clearCache'] ?? null);
        self::assertSame(10.0, $api->lastBody['weight'] ?? null);
    }
}
