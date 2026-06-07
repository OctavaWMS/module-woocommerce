<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\UiBranding;

class ShippingMethod extends \WC_Shipping_Method
{
    public const METHOD_ID = 'delivery_with_orderadmin';

    public function __construct(int $instanceId = 0, ?CheckoutCalculator $calculator = null)
    {
        $this->id = self::METHOD_ID;
        $this->instance_id = $instanceId;
        $this->method_title = UiBranding::appName();
        $this->method_description = __(
            'Calculated carrier delivery rates from OctavaWMS.',
            'octavawms'
        );
        $this->supports = ['shipping-zones', 'instance-settings'];
        $this->calculator = $calculator ?? new CheckoutCalculator(new BackendApiClient());

        $this->init();
    }

    private CheckoutCalculator $calculator;

    public function init(): void
    {
        $this->enabled = 'yes';
        $this->title = UiBranding::appName();
    }

    /**
     * @param array<string, mixed> $package
     */
    public function calculate_shipping($package = []): void
    {
        if (! is_array($package)) {
            return;
        }

        $rates = $this->calculator->calculatePackage($package);
        $sessionRates = [];
        foreach ($rates as $rate) {
            $rateId = (string) ($rate['optionId'] ?? '');
            if ($rateId === '') {
                continue;
            }
            $sessionRates[$rateId] = $rate;
            $this->add_rate([
                'id' => $rateId,
                'label' => (string) ($rate['title'] ?? $this->title),
                'cost' => (float) ($rate['cost'] ?? 0),
                'meta_data' => [
                    'octavawms_method_kind' => (string) ($rate['methodKind'] ?? 'address'),
                ],
            ]);
        }

        CheckoutSession::storeRates($sessionRates);
    }
}
