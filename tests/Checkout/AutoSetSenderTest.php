<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Checkout;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Checkout\AutoSetSender;
use PHPUnit\Framework\Assert;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class AutoSetSenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wc_get_logger')->justReturn(new class () {
            public function log(string $level, string $message, array $context = []): void
            {
                unset($level, $message, $context);
            }
        });
    }

    public function testReturnsConfiguredSenderWithoutApiCalls(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::never())->method('fetchActiveSendersPreview');

        $service = new AutoSetSender($api);
        $result = $service->resolve(13259, ['settings' => []], 77);

        self::assertSame(77, $result['sender_id']);
        self::assertSame('configured', $result['outcome']);
    }

    public function testAutoSetsWhenExactlyOneActiveSenderExists(): void
    {
        $api = new class () extends BackendApiClient {
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

            public function patchIntegrationSource(int $sourceId, array $body): array
            {
                Assert::assertSame(13259, $sourceId);
                Assert::assertSame(
                    19227,
                    $body['settings']['DeliveryServices']['options']['sender'] ?? null
                );

                return [
                    'ok' => true,
                    'status' => 200,
                    'data' => [],
                    'raw' => '',
                    'response_headers' => [],
                ];
            }
        };

        $service = new AutoSetSender($api);
        $result = $service->resolve(13259, ['settings' => ['DeliveryServices' => ['options' => []]]], 0);

        self::assertSame(19227, $result['sender_id']);
        self::assertSame('auto_set', $result['outcome']);
        self::assertSame(1, $result['sender_count']);
    }

    public function testSkipsWhenMultipleActiveSendersExist(): void
    {
        $api = new class () extends BackendApiClient {
            public function fetchActiveSendersPreview(int $limit = 2): array
            {
                return [
                    'items' => [
                        ['id' => 1, 'state' => 'active'],
                        ['id' => 2, 'state' => 'active'],
                    ],
                    'has_more' => false,
                    'ok' => true,
                    'message' => '',
                    'diagnostics' => [],
                ];
            }
        };

        $service = new AutoSetSender($api);
        $result = $service->resolve(13259, null, 0);

        self::assertSame(0, $result['sender_id']);
        self::assertSame('multiple_senders', $result['outcome']);
        self::assertSame(2, $result['sender_count']);
    }

    public function testSkipsWhenNoActiveSendersExist(): void
    {
        $api = new class () extends BackendApiClient {
            public function fetchActiveSendersPreview(int $limit = 2): array
            {
                unset($limit);

                return [
                    'items' => [],
                    'has_more' => false,
                    'ok' => true,
                    'message' => '',
                    'diagnostics' => ['parse_note' => 'no_active_senders_in_response'],
                ];
            }
        };

        $service = new AutoSetSender($api);
        $result = $service->resolve(13259, null, 0);

        self::assertSame(0, $result['sender_id']);
        self::assertSame('no_active_senders', $result['outcome']);
        self::assertSame(0, $result['sender_count']);
    }

    public function testSurfacesSendersApiErrorWithDiagnostics(): void
    {
        $api = new class () extends BackendApiClient {
            public function fetchActiveSendersPreview(int $limit = 2): array
            {
                unset($limit);

                return [
                    'items' => [],
                    'has_more' => false,
                    'ok' => false,
                    'message' => 'Unauthorized',
                    'diagnostics' => [
                        'api_base_url' => 'https://pro.oawms.com',
                        'bearer_token_configured' => false,
                        'request' => ['method' => 'GET', 'url' => 'https://pro.oawms.com/api/delivery-services/senders?page=1'],
                        'response' => ['http_status' => 401],
                        'parse_note' => 'senders_api_error',
                    ],
                ];
            }
        };

        $service = new AutoSetSender($api);
        $result = $service->resolve(13259, null, 0);

        self::assertSame('senders_api_error', $result['outcome']);
        self::assertSame('Unauthorized', $result['message'] ?? null);
        self::assertSame(401, $result['diagnostics']['response']['http_status'] ?? null);
    }
}
