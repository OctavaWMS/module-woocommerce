<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Api;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class LabelServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $logger = new class () {
            public function log(string $level, string $message, array $context = []): void
            {
            }
        };
        Functions\when('wc_get_logger')->justReturn($logger);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('trailingslashit')->alias(static fn (string $path): string => rtrim($path, '/\\') . '/');
        Functions\when('wp_upload_dir')->alias(static fn (): array => [
            'basedir' => sys_get_temp_dir(),
            'error' => false,
        ]);
        Functions\when('wp_mkdir_p')->alias(static function (string $dir): bool {
            if (is_dir($dir)) {
                return true;
            }

            return @mkdir($dir, 0777, true);
        });
        Functions\when('wp_generate_password')->justReturn('testtok12345678901234');
        Functions\when('sanitize_file_name')->alias(static fn (string $f): string => $f);
    }

    public function testRequestLabelBootstrapsQueueWhenMissingThenStoresSyncPdf(): void
    {
        $client = new class extends BackendApiClient {
            public int $findPreprocessingCalls = 0;

            /** @return list<array<string, mixed>> */
            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                return [['id' => 100]];
            }

            /** @return array{ok: bool, task_id: int|null, queue_id: int|null} */
            public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
            {
                ++$this->findPreprocessingCalls;
                if ($this->findPreprocessingCalls === 1) {
                    return ['ok' => true, 'task_id' => null, 'queue_id' => null];
                }

                return ['ok' => true, 'task_id' => null, 'queue_id' => 200];
            }

            /** @return array<string, mixed>|null */
            public function getShipmentById(int $shipmentId): ?array
            {
                return [
                    '_embedded' => [
                        'sender' => ['id' => 55],
                    ],
                ];
            }

            /** @return array{ok: bool, message: string, queue_id: int|null} */
            public function createProcessingQueueForSender(int $deliveryRequestId, ?int $senderId, bool $retried = false): array
            {
                return ['ok' => true, 'message' => '', 'queue_id' => null];
            }

            /** @return array{ok: bool, pdf: string|null, content_type: string, task_id: int|null, message?: string} */
            public function createOrUpdatePreprocessingTask(?int $taskId, array $payload, bool $retried = false): array
            {
                return ['ok' => true, 'pdf' => '%PDF-1.4', 'content_type' => 'application/pdf', 'task_id' => null];
            }
        };

        $service = new LabelService($client);
        $result = $service->requestLabel('order-xyz', 250, 10, 20, 30, null, null, []);

        self::assertSame('success', $result['status']);
        self::assertNotEmpty($result['label_file'] ?? null);
        self::assertFileExists((string) $result['label_file']);
        self::assertSame(2, $client->findPreprocessingCalls);
        @unlink((string) $result['label_file']);
    }

    public function testRequestLabelUsesQueueIdFromCreateResponseWithoutSecondTaskLookup(): void
    {
        $client = new class extends BackendApiClient {
            public int $findPreprocessingCalls = 0;

            public ?int $capturedQueueInPreprocessingPayload = null;

            /** @return list<array<string, mixed>> */
            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                return [['id' => 101]];
            }

            /** @return array{ok: bool, task_id: int|null, queue_id: int|null} */
            public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
            {
                ++$this->findPreprocessingCalls;

                return ['ok' => true, 'task_id' => null, 'queue_id' => null];
            }

            /** @return array<string, mixed>|null */
            public function getShipmentById(int $shipmentId): ?array
            {
                return null;
            }

            /** @return array{ok: bool, message: string, queue_id: int|null} */
            public function createProcessingQueueForSender(int $deliveryRequestId, ?int $senderId, bool $retried = false): array
            {
                return ['ok' => true, 'message' => '', 'queue_id' => 888];
            }

            /** @return array{ok: bool, pdf: string|null, content_type: string, task_id: int|null, message?: string} */
            public function createOrUpdatePreprocessingTask(?int $taskId, array $payload, bool $retried = false): array
            {
                $q = $payload['queue'] ?? null;
                $this->capturedQueueInPreprocessingPayload = is_int($q) ? $q : (is_numeric($q) ? (int) $q : null);

                return ['ok' => true, 'pdf' => '%PDF', 'content_type' => 'application/pdf', 'task_id' => null];
            }
        };

        $service = new LabelService($client);
        $result = $service->requestLabel('order-abc', 100, 100, 100, 100, null, null, []);
        self::assertSame('success', $result['status']);
        self::assertSame(1, $client->findPreprocessingCalls);
        self::assertSame(888, $client->capturedQueueInPreprocessingPayload);
        @unlink((string) ($result['label_file'] ?? ''));
    }

    public function testRequestLabelReturnsErrorWhenQueueBootstrapFails(): void
    {
        $client = new class extends BackendApiClient {
            /** @return list<array<string, mixed>> */
            public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
            {
                return [['id' => 1]];
            }

            /** @return array{ok: bool, task_id: int|null, queue_id: int|null} */
            public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
            {
                return ['ok' => true, 'task_id' => null, 'queue_id' => null];
            }

            /** @return array<string, mixed>|null */
            public function getShipmentById(int $shipmentId): ?array
            {
                return [];
            }

            /** @return array{ok: bool, message: string, queue_id: int|null} */
            public function createProcessingQueueForSender(int $deliveryRequestId, ?int $senderId, bool $retried = false): array
            {
                return ['ok' => false, 'message' => 'Sender profile should be set', 'queue_id' => null];
            }
        };

        $service = new LabelService($client);
        $result = $service->requestLabel('x', 1, 1, 1, 1, null, null, []);

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('Sender profile should be set', (string) ($result['message'] ?? ''));
    }
}
