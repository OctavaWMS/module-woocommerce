<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Api;

use OctavaWMS\WooCommerce\Activation;
use OctavaWMS\WooCommerce\PluginLog;

class LabelService
{
    public const ORDER_META_LABEL_URL = '_octavawms_label_url';
    public const ORDER_META_LABEL_FILE = '_octavawms_label_file';

    private BackendApiClient $apiClient;

    public function __construct(BackendApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * @return array<string, mixed>
     */
    private function labelContext(string $externalOrderId, ?int $wcOrderId): array
    {
        $ctx = ['external_order_id' => $externalOrderId];
        if ($wcOrderId !== null) {
            $ctx['order_id'] = $wcOrderId;
        }

        return $ctx;
    }

    /**
     * Generate a label for an order via the generic preprocessing-task pipeline.
     *
     * @param list<string> $extIdCandidates When non-empty, used with {@see BackendApiClient::findShipmentsForConnector}; otherwise only {@see $externalOrderId} is tried for extId shipment listing.
     * @param array<string, mixed>|null $backendOrderEntity Octava order entity when already resolved (improves shipment discovery by backend order id / embedded delivery request).
     * @param int|null $wcOrderId WooCommerce order ID for structured logs (admin actions).
     * @return array{status:string,label_url?:string,label_file?:string,message?:string}
     */
    public function requestLabel(
        string $externalOrderId,
        int $weightGrams = 100,
        int $dimX = 100,
        int $dimY = 100,
        int $dimZ = 100,
        ?int $wcOrderId = null,
        ?array $backendOrderEntity = null,
        array $extIdCandidates = [],
    ): array {
        PluginLog::log('info', 'labels', $this->labelContext($externalOrderId, $wcOrderId) + [
            'note' => 'preprocessing_task_start',
            'weight_grams' => $weightGrams,
        ]);

        $candidates = $extIdCandidates !== [] ? $extIdCandidates : [$externalOrderId];
        $shipments = $this->apiClient->findShipmentsForConnector($backendOrderEntity, $candidates);
        $shipment = $shipments[0] ?? null;

        if (! is_array($shipment) || ! isset($shipment['id'])) {
            PluginLog::log('error', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
                'request' => null,
                'response' => null,
                'note' => 'no_shipment_for_ext_id',
            ]));

            return ['status' => 'error', 'message' => 'No shipment found for this order in OctavaWMS. Upload the order first.'];
        }

        $deliveryRequestId = (int) $shipment['id'];

        $tasksInfo = $this->apiClient->findPreprocessingTasksForShipment($deliveryRequestId);
        $existingTaskId = $tasksInfo['task_id'];
        $queueId = $tasksInfo['queue_id'];

        if ($queueId === null) {
            $detail = $this->apiClient->getShipmentById($deliveryRequestId);
            $senderId = BackendApiClient::extractSenderIdFromDeliveryRequestDetail($detail);
            PluginLog::log('info', 'labels', $this->labelContext($externalOrderId, $wcOrderId) + [
                'note' => 'processing_queue_missing_creating',
                'delivery_request_id' => $deliveryRequestId,
                'sender_id' => $senderId,
            ]);
            $queueName = BackendApiClient::preprocessingQueueDisplayName(is_array($detail) ? $detail : null);
            $created = $this->apiClient->createProcessingQueueForSender($deliveryRequestId, $senderId, false, $queueName);
            if (! $created['ok']) {
                PluginLog::log('error', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
                    'request' => null,
                    'response' => null,
                    'note' => 'create_processing_queue_failed',
                    'delivery_request_id' => $deliveryRequestId,
                    'sender_id' => $senderId,
                    'message' => $created['message'],
                ]));

                return ['status' => 'error', 'message' => $created['message']];
            }
            if ($created['queue_id'] !== null) {
                $queueId = $created['queue_id'];
            } else {
                $tasksInfo = $this->apiClient->findPreprocessingTasksForShipment($deliveryRequestId);
                $existingTaskId = $tasksInfo['task_id'];
                $queueId = $tasksInfo['queue_id'];
            }
        }

        if ($queueId === null) {
            PluginLog::log('error', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
                'request' => null,
                'response' => null,
                'note' => 'no_processing_queue_after_create',
                'delivery_request_id' => $deliveryRequestId,
            ]));

            return ['status' => 'error', 'message' => 'No processing queue found for this shipment.'];
        }

        PluginLog::log('info', 'labels', $this->labelContext($externalOrderId, $wcOrderId) + [
            'note' => 'preprocessing_context',
            'delivery_request_id' => $deliveryRequestId,
            'existing_task_id' => $existingTaskId,
            'queue_id' => $queueId,
        ]);

        $payload = [
            'state' => 'measured',
            'deliveryRequest' => [
                'id' => $deliveryRequestId,
                'sendDate' => date('Y-m-d'),
                'weight' => max(1, $weightGrams),
                'dimensions' => [
                    'x' => max(1, $dimX),
                    'y' => max(1, $dimY),
                    'z' => max(1, $dimZ),
                ],
            ],
            'queue' => $queueId,
        ];

        $createResult = $this->apiClient->createOrUpdatePreprocessingTask($existingTaskId, $payload);

        if (! $createResult['ok']) {
            return ['status' => 'error', 'message' => $createResult['message'] ?? 'Failed to create preprocessing task.'];
        }

        if ($createResult['pdf'] !== null) {
            PluginLog::log('info', 'labels', $this->labelContext($externalOrderId, $wcOrderId) + [
                'note' => 'synchronous_pdf_received',
            ]);
            $ext = str_contains($createResult['content_type'], 'text/html') ? 'html' : 'pdf';
            $filePath = $this->storeBinaryLabel($createResult['pdf'], $externalOrderId, $ext, $wcOrderId);

            if ($filePath === null) {
                return ['status' => 'error', 'message' => 'Unable to store synchronous label file.'];
            }

            return ['status' => 'success', 'label_file' => $filePath];
        }

        $taskId = $createResult['task_id'];
        if ($taskId === null) {
            PluginLog::log('error', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
                'request' => null,
                'response' => null,
                'note' => 'no_task_id_after_preprocessing_call',
                'delivery_request_id' => $deliveryRequestId,
            ]));

            return ['status' => 'error', 'message' => 'No task ID returned from preprocessing task endpoint.'];
        }

        return $this->pollForLabel($taskId, $externalOrderId, $wcOrderId);
    }

    /**
     * @return array{status:string,label_file?:string,message?:string}
     */
    private function pollForLabel(int $taskId, string $externalOrderId, ?int $wcOrderId, int $maxAttempts = 10, int $sleepSeconds = 3): array
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = $this->apiClient->downloadPreprocessingTaskLabel($taskId);

            if ($result['ready'] && $result['pdf'] !== null) {
                PluginLog::log('info', 'labels', $this->labelContext($externalOrderId, $wcOrderId) + [
                    'note' => 'poll_got_pdf',
                    'task_id' => $taskId,
                    'attempt' => $i + 1,
                ]);
                $ext = str_contains($result['content_type'], 'text/html') ? 'html' : 'pdf';
                $filePath = $this->storeBinaryLabel($result['pdf'], $externalOrderId, $ext, $wcOrderId);

                if ($filePath === null) {
                    return ['status' => 'error', 'message' => 'Unable to store label file.'];
                }

                return ['status' => 'success', 'label_file' => $filePath];
            }

            // HTTP/out-of-band errors are logged in BackendApiClient::downloadPreprocessingTaskLabel.

            if ($i < $maxAttempts - 1) {
                sleep($sleepSeconds);
            }
        }

        PluginLog::log('warning', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
            'request' => null,
            'response' => null,
            'note' => 'label_generation_timed_out',
            'task_id' => $taskId,
        ]));

        return ['status' => 'error', 'message' => 'Label generation timed out. The task may still be processing — try again in a moment.'];
    }

    /**
     * Download an existing preprocessing-task label and persist it to disk (same polling as {@see requestLabel}).
     *
     * @return array{status:string,label_file?:string,message?:string}
     */
    public function fetchExistingTaskLabel(int $taskId, string $externalOrderId, ?int $wcOrderId = null): array
    {
        return $this->pollForLabel($taskId, $externalOrderId, $wcOrderId);
    }

    /**
     * @return string|null Absolute file path on success, null on failure.
     */
    private function storeBinaryLabel(string $binary, string $externalOrderId, string $extension, ?int $wcOrderId = null): ?string
    {
        $uploadDir = \wp_upload_dir();

        if (! empty($uploadDir['error'])) {
            PluginLog::log('error', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
                'request' => null,
                'response' => null,
                'note' => 'upload_dir_error',
                'wp_upload_error' => (string) $uploadDir['error'],
            ]));

            return null;
        }

        $targetDirectory = trailingslashit((string) $uploadDir['basedir']) . Activation::LABEL_SUBDIR . '/';

        if (! \wp_mkdir_p($targetDirectory)) {
            PluginLog::log('error', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
                'request' => null,
                'response' => null,
                'note' => 'mkdir_failed',
                'dir' => $targetDirectory,
            ]));

            return null;
        }

        $safeExt = preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        $safeExt = $safeExt !== '' ? $safeExt : 'pdf';
        $secureToken = \wp_generate_password(20, false, false);
        $fileName = \sanitize_file_name(sprintf('label-%s-%s.%s', preg_replace('/[^a-zA-Z0-9_-]/', '', $externalOrderId), $secureToken, $safeExt));
        $filePath = $targetDirectory . $fileName;

        $bytesWritten = file_put_contents($filePath, $binary);

        if ($bytesWritten === false) {
            PluginLog::log('error', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
                'request' => null,
                'response' => null,
                'note' => 'file_write_failed',
                'file' => $filePath,
            ]));

            return null;
        }

        PluginLog::log('info', 'labels', array_merge($this->labelContext($externalOrderId, $wcOrderId), [
            'note' => 'label_file_written',
            'file' => $filePath,
            'bytes' => $bytesWritten,
        ]));

        return $filePath;
    }
}
