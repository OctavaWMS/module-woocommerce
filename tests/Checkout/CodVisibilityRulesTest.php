<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Checkout;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Checkout\CheckoutSession;
use OctavaWMS\WooCommerce\Checkout\CodVisibilityRules;
use OctavaWMS\WooCommerce\Checkout\ShippingMethod;
use OctavaWMS\WooCommerce\Options;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class CodVisibilityRulesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
        $GLOBALS['octavawms_test_wc'] = null;
        $GLOBALS['octavawms_checkout_session'] = [];
        $this->stored = [];

        Functions\when('sanitize_text_field')->alias(static fn (mixed $value): string => trim((string) $value));
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return $this->stored[$name] ?? $default;
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['octavawms_checkout_session']);
        parent::tearDown();
    }

    public function testRegisterAddsPaymentGatewayFilter(): void
    {
        Functions\expect('add_filter')
            ->once()
            ->with(
                'woocommerce_available_payment_gateways',
                \Mockery::on(static function (mixed $callback): bool {
                    return is_array($callback)
                        && $callback[0] instanceof CodVisibilityRules
                        && ($callback[1] ?? null) === 'filterAvailablePaymentGateways';
                }),
                20
            );

        (new CodVisibilityRules())->register();

        self::assertTrue(true);
    }

    public function testHidesCodForMatchingDeliveryTypeRule(): void
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
        $this->storedRules([
            [
                'enabled' => true,
                'payment_handle' => 'cod',
                'mode' => 'exclude',
                'match' => [
                    'scope' => 'delivery_type',
                    'delivery_type' => 'self_service_point',
                    'delivery_service_id' => '5',
                ],
            ],
        ]);

        $filtered = (new CodVisibilityRules())->filterAvailablePaymentGateways([
            'cod' => (object) ['id' => 'cod'],
            'bacs' => (object) ['id' => 'bacs'],
        ]);

        self::assertArrayNotHasKey('cod', $filtered);
        self::assertArrayHasKey('bacs', $filtered);
    }

    public function testMoreSpecificAllowRuleKeepsCod(): void
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
        $this->storedRules([
            [
                'enabled' => true,
                'payment_handle' => 'cod',
                'mode' => 'exclude',
                'match' => [
                    'scope' => 'delivery_type',
                    'delivery_type' => 'service_point',
                    'delivery_service_id' => '5',
                ],
            ],
            [
                'enabled' => true,
                'payment_handle' => 'cod',
                'mode' => 'include',
                'match' => [
                    'scope' => 'rate',
                    'delivery_type' => 'service_point',
                    'delivery_service_id' => '5',
                    'rate_id' => '12',
                ],
            ],
        ]);

        $filtered = (new CodVisibilityRules())->filterAvailablePaymentGateways([
            'cod' => (object) ['id' => 'cod'],
            'bacs' => (object) ['id' => 'bacs'],
        ]);

        self::assertArrayHasKey('cod', $filtered);
    }

    public function testDoesNotHideCodForNonOctavaShippingMethod(): void
    {
        $_POST['shipping_method'] = ['flat_rate:1'];
        $this->storedRules([
            [
                'enabled' => true,
                'payment_handle' => 'cod',
                'mode' => 'exclude',
                'match' => [
                    'scope' => 'delivery_type',
                    'delivery_type' => 'simple',
                ],
            ],
        ]);

        $filtered = (new CodVisibilityRules())->filterAvailablePaymentGateways([
            'cod' => (object) ['id' => 'cod'],
        ]);

        self::assertArrayHasKey('cod', $filtered);
    }

    public function testSelectedShippingMethodFallsBackToWooSession(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':8:20:0';
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 8,
                'rate' => 20,
                'methodKind' => 'address',
            ],
        ]);
        WC()->session->set('chosen_shipping_methods', [$rateId]);
        $this->storedRules([
            [
                'enabled' => true,
                'payment_handle' => 'cod',
                'mode' => 'exclude',
                'match' => [
                    'scope' => 'carrier',
                    'delivery_type' => 'any',
                    'delivery_service_id' => '8',
                ],
            ],
        ]);

        $filtered = (new CodVisibilityRules())->filterAvailablePaymentGateways([
            'cod' => (object) ['id' => 'cod'],
        ]);

        self::assertArrayNotHasKey('cod', $filtered);
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    private function storedRules(array $rules): void
    {
        $this->stored['woocommerce_octavawms_settings'] = [
            Options::COD_VISIBILITY_RULES_JSON => json_encode($rules, JSON_THROW_ON_ERROR),
        ];
    }
}
