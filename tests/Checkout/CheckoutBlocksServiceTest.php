<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Checkout;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Checkout\CheckoutBlocksService;
use OctavaWMS\WooCommerce\Checkout\CheckoutDeliveryService;
use OctavaWMS\WooCommerce\Checkout\CheckoutSession;
use OctavaWMS\WooCommerce\Checkout\ShippingMethod;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class CheckoutBlocksServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['octavawms_checkout_session'] = [];
        Functions\when('sanitize_text_field')->alias(static fn (mixed $value): string => trim((string) $value));
        Functions\when('absint')->alias(static fn (mixed $value): int => abs((int) $value));
    }

    public function testStoreSelectionPersistsPickupPointForKnownRate(): void
    {
        $rateId = ShippingMethod::METHOD_ID . ':12:34:0';
        CheckoutSession::storeRates([
            $rateId => [
                'deliveryService' => 12,
                'rate' => 34,
                'methodKind' => 'locker',
            ],
        ]);

        $service = new CheckoutBlocksService(new CheckoutDeliveryService(new BackendApiClient()));
        $service->storeSelection(['rateId' => $rateId, 'servicePointId' => 91]);

        self::assertSame([
            'rateId' => $rateId,
            'deliveryService' => 12,
            'rate' => 34,
            'servicePoint' => 91,
            'methodKind' => 'locker',
        ], CheckoutSession::selection());
    }

    public function testStoreSelectionRejectsUnknownRate(): void
    {
        CheckoutSession::storeSelection(['rateId' => 'stale']);

        $service = new CheckoutBlocksService(new CheckoutDeliveryService(new BackendApiClient()));
        $service->storeSelection(['rateId' => ShippingMethod::METHOD_ID . ':999', 'servicePointId' => 91]);

        self::assertSame([], CheckoutSession::selection());
    }
}
