<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Checkout;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Checkout\CheckoutDeliveryService;
use OctavaWMS\WooCommerce\Checkout\CheckoutSession;
use OctavaWMS\WooCommerce\Checkout\ShippingMethod;
use OctavaWMS\WooCommerce\UiBranding;
use PHPUnit\Framework\Assert;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class CheckoutDeliveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['octavawms_test_wc'] = null;
        Functions\when('__')->returnArg();
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('sanitize_text_field')->alias(static fn (mixed $value): string => trim((string) $value));
        Functions\when('wp_unslash')->returnArg();
        Functions\when('absint')->alias(static fn (mixed $value): int => abs((int) $value));
        Functions\when('esc_url')->alias(static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_attr')->alias(static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }

    public function testCheckoutAssetsExposeIzpratiAttributionWhenNoRemoveBrandingPlan(): void
    {
        $localized = null;

        Filters\expectApplied('octavawms_brand_pack')
            ->andReturn(UiBranding::PACK_IZPRATI);
        Functions\when('is_checkout')->justReturn(true);
        Functions\when('is_cart')->justReturn(false);
        Functions\when('is_order_received_page')->justReturn(false);
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        Functions\when('plugins_url')->alias(static fn (string $path, string $pluginFile): string => $pluginFile . '/' . $path);
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('admin_url')->justReturn('https://shop.test/wp-admin/admin-ajax.php');
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\expect('wp_localize_script')
            ->once()
            ->with('octavawms-checkout-delivery', 'octavawmsCheckoutDelivery', \Mockery::on(static function (array $payload) use (&$localized): bool {
                $localized = $payload;

                return true;
            }))
            ->andReturn(null);

        (new CheckoutDeliveryService(new BackendApiClient()))->enqueueAssets();

        self::assertIsArray($localized);
        self::assertTrue($localized['showIzpratiAttribution'] ?? false);
        self::assertSame('Работи с ', $localized['strings']['poweredByPrefix'] ?? null);
        self::assertSame('ИЗПРАТИ.БГ', $localized['strings']['poweredByMarkWord'] ?? null);
        self::assertArrayNotHasKey('attributionUrl', $localized['strings']);
    }

    public function testValidationRequiresPickupPointForOfficeRate(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 5,
                'rate' => 12,
                'methodKind' => 'office',
            ],
        ]);
        $_POST['shipping_method'] = [$rateId];

        $errors = new class () {
            /** @var array<string, string> */
            public array $errors = [];

            public function add(string $code, string $message): void
            {
                $this->errors[$code] = $message;
            }
        };

        $service = new CheckoutDeliveryService(new BackendApiClient());
        $service->validateCheckout(['shipping_method' => [$rateId]], $errors);

        self::assertSame('Choose a pickup point before placing the order.', $errors->errors['octavawms_delivery'] ?? null);
    }

    public function testPersistsSelectedDeliveryDataOnShippingItemAndOrderMeta(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 5,
                'rate' => 12,
                'methodKind' => 'locker',
            ],
        ]);
        $_POST['shipping_method'] = [$rateId];
        $_POST['octavawms_service_point_id'] = '91';

        $service = new CheckoutDeliveryService(new BackendApiClient());
        $item = new \WC_Order_Item_Shipping();
        $order = new \WC_Order();

        $service->persistShippingItemMeta($item, 0, [], $order);
        $service->persistOrderMeta($order, []);

        self::assertSame(5, $item->meta['deliveryService'] ?? null);
        self::assertSame(12, $item->meta['rate'] ?? null);
        self::assertSame(91, $item->meta['servicePoint'] ?? null);
        self::assertSame(5, $order->updatedMeta['_octavawms_delivery_service'] ?? null);
        self::assertSame(12, $order->updatedMeta['_octavawms_delivery_rate'] ?? null);
        self::assertSame(91, $order->updatedMeta['_octavawms_service_point'] ?? null);
    }

    public function testServicePointSearchRequiresSelectedCarrierAndLocality(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeContext([
            'postcode' => '9002',
            'country' => 'BG',
            'localityId' => 900,
        ]);
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 5,
                'rate' => 12,
                'methodKind' => 'locker',
                'title' => 'Speedy - ДО АВТОМАТ',
                'servicePoints' => [],
            ],
        ]);
        $_POST['rate_id'] = $rateId;
        $_POST['search'] = 'кракра';

        $api = new class () extends BackendApiClient {
            /** @var array<string, mixed>|null */
            public ?array $lastParams = null;

            public function fetchServicePoints(array $params): array
            {
                $this->lastParams = $params;

                return [
                    'items' => [[
                        'id' => 91,
                        'name' => 'ВАРНА - КРАКРА (АВТОМАТ)',
                        'address' => 'гр. ВАРНА ул. КРАКРА No 44А',
                        'type' => 'self_service_point',
                        'geo' => 'SRID=4326;POINT(27.899952 43.207506)',
                    ]],
                    'total_pages' => 1,
                ];
            }
        };

        Functions\expect('wp_send_json_success')
            ->once()
            ->with(\Mockery::on(static function (array $payload) use ($api): bool {
                Assert::assertSame([
                    'localityId' => 900,
                    'deliveryServiceId' => 5,
                    'servicePointType' => 'self_service_point',
                    'search' => 'кракра',
                    'page' => 1,
                    'perPage' => 100,
                ], $api->lastParams);
                Assert::assertSame(91, $payload['items'][0]['id'] ?? null);
                Assert::assertSame(43.207506, $payload['items'][0]['lat'] ?? null);
                Assert::assertSame(27.899952, $payload['items'][0]['lng'] ?? null);

                return true;
            }))
            ->andThrow(new \RuntimeException('wp_send_json_success'));

        $this->expectExceptionMessage('wp_send_json_success');
        (new CheckoutDeliveryService($api))->handleServicePoints();
    }

    public function testInitialServicePointBlockFetchesFiveBackendPoints(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeContext([
            'postcode' => '9002',
            'country' => 'BG',
            'localityId' => 900,
        ]);
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 5,
                'rate' => 12,
                'methodKind' => 'office',
                'servicePoints' => [[
                    'id' => 999,
                    'name' => 'Cached calculator point',
                ]],
            ],
        ]);
        $_POST['rate_id'] = $rateId;
        $_POST['search'] = '';

        $api = new class () extends BackendApiClient {
            /** @var array<string, mixed>|null */
            public ?array $lastParams = null;

            public function fetchServicePoints(array $params): array
            {
                $this->lastParams = $params;
                $items = [];
                for ($i = 1; $i <= 5; $i++) {
                    $items[] = [
                        'id' => $i,
                        'name' => 'ВАРНА - ОФИС ' . $i,
                        'rawAddress' => 'гр. ВАРНА ул. ТЕСТ No ' . $i,
                        'type' => 'service_point',
                        '_embedded' => [
                            'locality' => [
                                'name' => 'Варна',
                                'postcode' => '9000',
                            ],
                        ],
                    ];
                }

                return [
                    'items' => $items,
                    'total_pages' => 1,
                ];
            }
        };

        Functions\expect('wp_send_json_success')
            ->once()
            ->with(\Mockery::on(static function (array $payload) use ($api): bool {
                Assert::assertSame([
                    'localityId' => 900,
                    'deliveryServiceId' => 5,
                    'servicePointType' => 'service_point',
                    'search' => '',
                    'page' => 1,
                    'perPage' => 5,
                ], $api->lastParams);
                Assert::assertCount(5, $payload['items']);
                Assert::assertSame(1, $payload['items'][0]['id'] ?? null);
                Assert::assertSame('гр. ВАРНА ул. ТЕСТ № 1', $payload['items'][0]['address'] ?? null);
                Assert::assertSame('Варна', $payload['items'][0]['city'] ?? null);
                Assert::assertSame('9000', $payload['items'][0]['postcode'] ?? null);
                Assert::assertSame('Office', $payload['items'][0]['typeLabel'] ?? null);

                return true;
            }))
            ->andThrow(new \RuntimeException('wp_send_json_success'));

        $this->expectExceptionMessage('wp_send_json_success');
        (new CheckoutDeliveryService($api))->handleServicePoints();
    }

    public function testDebugCheckoutContextLogsServicePointBackendRequest(): void
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

        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeContext([
            'postcode' => '9002',
            'country' => 'BG',
            'localityId' => 900,
            'debug' => true,
        ]);
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 5,
                'rate' => 12,
                'methodKind' => 'office',
                'title' => 'Speedy - ДО ОФИС',
                'servicePoints' => [],
            ],
        ]);
        $_POST['rate_id'] = $rateId;
        $_POST['search'] = '';

        $api = new class () extends BackendApiClient {
            public function fetchServicePoints(array $params): array
            {
                unset($params);

                return [
                    'items' => [[
                        'id' => 91,
                        'name' => 'ВАРНА - ОФИС',
                        'address' => 'гр. ВАРНА ул. ГЕН. КОЛЕВ No 68',
                        'type' => 'service_point',
                    ]],
                    'total_pages' => 1,
                    'request' => [
                        'method' => 'GET',
                        'url' => 'https://api.test/api/delivery-services/service-points?filter[0][field]=deliveryService',
                        'headers' => [],
                        'body' => null,
                    ],
                    'response' => [
                        'http_status' => 200,
                        'json' => ['_embedded' => ['servicePoints' => [['id' => 91]]]],
                    ],
                ];
            }
        };

        Functions\expect('wp_send_json_success')
            ->once()
            ->with(\Mockery::type('array'))
            ->andThrow(new \RuntimeException('wp_send_json_success'));

        try {
            (new CheckoutDeliveryService($api))->handleServicePoints();
        } catch (\RuntimeException $e) {
            self::assertSame('wp_send_json_success', $e->getMessage());
        }

        self::assertCount(1, $logged);
        self::assertSame('debug', $logged[0]['level']);
        self::assertStringContainsString('checkout_service_points', $logged[0]['message']);
        self::assertStringContainsString('/api/delivery-services/service-points', $logged[0]['message']);
        self::assertStringContainsString('"item_count":1', $logged[0]['message']);
        self::assertStringContainsString('"address":"гр. ВАРНА ул. ГЕН. КОЛЕВ № 68"', $logged[0]['message']);
        self::assertStringContainsString('Full response JSON omitted', $logged[0]['message']);
        self::assertStringNotContainsString('_embedded', $logged[0]['message']);
        self::assertStringNotContainsString('servicePoints', $logged[0]['message']);
    }

    public function testServicePointNearMeSearchForwardsCoordinates(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeContext([
            'postcode' => '9002',
            'country' => 'BG',
            'localityId' => 900,
        ]);
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 5,
                'rate' => 12,
                'methodKind' => 'office',
                'servicePoints' => [],
            ],
        ]);
        $_POST['rate_id'] = $rateId;
        $_POST['search'] = '';
        $_POST['lat'] = '43.20751';
        $_POST['lng'] = '27.89995';

        $api = new class () extends BackendApiClient {
            /** @var array<string, mixed>|null */
            public ?array $lastParams = null;

            public function fetchServicePoints(array $params): array
            {
                $this->lastParams = $params;

                return [
                    'items' => [[
                        'id' => 92,
                        'name' => 'ВАРНА - ОФИС',
                        'address' => 'гр. ВАРНА ул. ГЕН. КОЛЕВ No 68',
                        'type' => 'service_point',
                        'geo' => '27.900000,43.208000',
                    ]],
                    'total_pages' => 1,
                ];
            }
        };

        Functions\expect('wp_send_json_success')
            ->once()
            ->with(\Mockery::on(static function (array $payload) use ($api): bool {
                Assert::assertSame([
                    'localityId' => 900,
                    'deliveryServiceId' => 5,
                    'servicePointType' => 'service_point',
                    'search' => '',
                    'page' => 1,
                    'perPage' => 100,
                    'lat' => 43.20751,
                    'lng' => 27.89995,
                    'sort' => 'distance',
                    'browserGeolocationEnabled' => true,
                ], $api->lastParams);
                Assert::assertSame(92, $payload['items'][0]['id'] ?? null);
                Assert::assertSame(43.208, $payload['items'][0]['lat'] ?? null);
                Assert::assertSame(27.9, $payload['items'][0]['lng'] ?? null);

                return true;
            }))
            ->andThrow(new \RuntimeException('wp_send_json_success'));

        $this->expectExceptionMessage('wp_send_json_success');
        (new CheckoutDeliveryService($api))->handleServicePoints();
    }

    public function testServicePointSearchDoesNotFetchWithoutLocality(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeContext([
            'postcode' => '9002',
            'country' => 'BG',
        ]);
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 5,
                'rate' => 12,
                'methodKind' => 'office',
                'servicePoints' => [],
            ],
        ]);
        $_POST['rate_id'] = $rateId;
        $_POST['search'] = 'd';

        $api = new class () extends BackendApiClient {
            public function fetchServicePoints(array $params): array
            {
                unset($params);
                Assert::fail('Service point search must not run without a locality id.');
            }
        };

        Functions\expect('wp_send_json_success')
            ->once()
            ->with(\Mockery::on(static function (array $payload): bool {
                Assert::assertSame([], $payload['items']);

                return true;
            }))
            ->andThrow(new \RuntimeException('wp_send_json_success'));

        $this->expectExceptionMessage('wp_send_json_success');
        (new CheckoutDeliveryService($api))->handleServicePoints();
    }

    public function testFormatsShippingLabelWithLogoAndWithoutWooPriceColon(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':5:12:0';
        CheckoutSession::storeRates([
            $rateId => [
                'carrierName' => 'Speedy',
                'carrierLogo' => 'https://cdn.test/speedy.png',
            ],
        ]);
        $method = new class ($rateId) {
            public function __construct(private readonly string $id)
            {
            }

            public function get_id(): string
            {
                return $this->id;
            }
        };

        $label = (new CheckoutDeliveryService(new BackendApiClient()))->formatShippingMethodFullLabel(
            'Speedy - ДО ОФИС: <span class="woocommerce-Price-amount amount">3.32€</span>',
            $method
        );

        self::assertStringContainsString('<img src="https://cdn.test/speedy.png"', $label);
        self::assertStringContainsString('alt="Speedy"', $label);
        self::assertStringContainsString('<span class="octavawms-shipping-rate-main">', $label);
        self::assertStringNotContainsString('ДО ОФИС:', $label);
        self::assertStringContainsString(
            '<span class="octavawms-shipping-rate-text">ДО ОФИС</span>',
            $label
        );
        self::assertStringContainsString(
            '<span class="octavawms-shipping-rate-price"><span class="woocommerce-Price-amount amount">3.32€</span></span>',
            $label
        );
        self::assertStringContainsString(
            '<span class="octavawms-shipping-rate-review-text">SPEEDY ДО ОФИС <span class="woocommerce-Price-amount amount">3.32€</span></span>',
            $label
        );
        self::assertStringNotContainsString('Speedy - ДО ОФИС', $label);
    }
}
