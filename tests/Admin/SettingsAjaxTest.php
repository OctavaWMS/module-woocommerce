<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Admin;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Admin\SettingsAjax;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class SettingsAjaxTest extends TestCase
{
    /** @var list<string> */
    private array $hooksAdded = [];

    /** @var array<string, mixed> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hooksAdded = [];
        $this->stored = [];

        Functions\when('__')->alias(static fn (string $text, $domain = null): string => $text);
        Functions\when('add_action')->alias(function (string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void {
            unset($callback, $priority, $acceptedArgs);
            $this->hooksAdded[] = $hook;
        });
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return $this->stored[$name] ?? $default;
        });
        Functions\when('update_option')->alias(function (string $name, $value): void {
            $this->stored[$name] = $value;
        });
    }

    public function testRegisterAddsPrivilegedAndNoprivAjaxHooks(): void
    {
        $api = $this->createMock(BackendApiClient::class);

        (new SettingsAjax($api))->register();

        self::assertSame(
            [
                'wp_ajax_' . SettingsAjax::ACTION,
                'wp_ajax_nopriv_' . SettingsAjax::ACTION,
            ],
            $this->hooksAdded
        );
    }

    public function testSaveCarrierMappingNormalizesSpeedyRowsAndPatchesSettingsPath(): void
    {
        $payload = [
            [
                'courierMetaKey' => 'courierName',
                'courierMetaValue' => 'Speedy',
                'wooDeliveryType' => 'office',
                'type' => 'office',
                'deliveryService' => 23,
                'rate' => null,
            ],
            [
                'courierMetaKey' => 'courierID',
                'courierMetaValue' => '18',
                'wooDeliveryType' => 'office',
                'type' => 'office',
                'deliveryService' => 23,
                'rate' => null,
            ],
        ];

        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::once())
            ->method('getIntegrationSource')
            ->with(77)
            ->willReturn([
                'settings' => [
                    'general' => ['url' => 'https://pagashop.com'],
                    'DeliveryServices' => [
                        'options' => [
                            'existing' => 'keep-me',
                        ],
                    ],
                ],
            ]);
        $api->expects(self::once())
            ->method('patchIntegrationSource')
            ->with(
                77,
                self::callback(static function (array $body) use ($payload): bool {
                    return ($body['settings']['general']['url'] ?? null) === 'https://pagashop.com'
                        && ($body['settings']['DeliveryServices']['options']['existing'] ?? null) === 'keep-me'
                        && ($body['settings']['DeliveryServices']['options']['carrierMapping'] ?? null) === $payload;
                })
            )
            ->willReturn([
                'ok' => true,
                'status' => 200,
                'data' => [],
                'raw' => '',
                'response_headers' => [],
            ]);

        $result = (new SettingsAjax($api))->saveCarrierMappingForSource(77, $payload);

        self::assertTrue($result['ok']);
        self::assertSame($payload, $result['carrierMapping']);
    }

    public function testSaveCarrierMappingReturnsBackendDetailOnPatchFailure(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        $api->method('getIntegrationSource')->willReturn(['settings' => []]);
        $api->method('patchIntegrationSource')->willReturn([
            'ok' => false,
            'status' => 422,
            'data' => ['detail' => 'DeliveryServices.options.carrierMapping is invalid'],
            'raw' => '',
            'response_headers' => [],
        ]);

        $result = (new SettingsAjax($api))->saveCarrierMappingForSource(77, [
            [
                'courierMetaKey' => 'courierName',
                'courierMetaValue' => 'Speedy',
                'wooDeliveryType' => 'office',
                'type' => 'office',
                'deliveryService' => 23,
                'rate' => null,
            ],
        ]);

        self::assertFalse($result['ok']);
        self::assertSame(422, $result['status']);
        self::assertSame('DeliveryServices.options.carrierMapping is invalid', $result['message']);
    }
}
