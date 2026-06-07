<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Checkout;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Checkout\CheckoutDeliveryService;
use OctavaWMS\WooCommerce\Checkout\CheckoutSession;
use OctavaWMS\WooCommerce\Checkout\ShippingMethod;
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
        self::assertStringNotContainsString('ДО ОФИС:', $label);
        self::assertStringContainsString('ДО ОФИС <span class="woocommerce-Price-amount amount">3.32€</span>', $label);
    }
}
