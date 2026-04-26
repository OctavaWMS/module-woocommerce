<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use WC_Logger;
use WP_Error;

class LabelService
{
    public const ORDER_META_LABEL_URL = '_octavawms_label_url';
    public const ORDER_META_LABEL_FILE = '_octavawms_label_file';

    private WC_Logger $logger;

    /**
     * @var array<string, mixed>
     */
    private array $logContext = ['source' => 'octavawms-labels'];

    public function __construct()
    {
        $this->logger = wc_get_logger();
    }

    /**
     * Request a label from OctavaWMS by external order id.
     *
     * @return array{status:string,label_url?:string,label_file?:string,message?:string}
     */
    public function requestLabel(string $externalOrderId): array
    {
        $endpoint = Options::getLabelEndpoint();
        $apiKey = Options::getApiKey();

        if ($endpoint === '') {
            $this->logger->error('Label request aborted: endpoint missing.', $this->logContext + ['external_order_id' => $externalOrderId]);

            return ['status' => 'error', 'message' => 'OctavaWMS endpoint not configured.'];
        }

        $payload = [
            'externalOrderId' => $externalOrderId,
        ];

        $headers = [
            'Accept' => 'application/json, application/pdf',
            'Content-Type' => 'application/json',
        ];

        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $this->logger->info('Sending label request to OctavaWMS.', $this->logContext + ['endpoint' => $endpoint, 'external_order_id' => $externalOrderId]);

        $response = wp_remote_post($endpoint, [
            'timeout' => 45,
            'headers' => $headers,
            'body' => wp_json_encode($payload),
        ]);

        if ($response instanceof WP_Error) {
            $this->logger->error('Label request failed with WP_Error.', $this->logContext + [
                'external_order_id' => $externalOrderId,
                'error' => $response->get_error_message(),
            ]);

            return ['status' => 'error', 'message' => $response->get_error_message()];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $contentType = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);

        $this->logger->info('Received response from OctavaWMS label endpoint.', $this->logContext + [
            'external_order_id' => $externalOrderId,
            'status_code' => $statusCode,
            'content_type' => $contentType,
        ]);

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('Label request returned non-2xx response.', $this->logContext + [
                'external_order_id' => $externalOrderId,
                'status_code' => $statusCode,
                'body_excerpt' => mb_substr($body, 0, 500),
            ]);

            return ['status' => 'error', 'message' => sprintf('Label request failed (%d).', $statusCode)];
        }

        if (str_contains($contentType, 'application/pdf') || str_contains($contentType, 'application/octet-stream')) {
            $filePath = $this->storeBinaryLabel($body, $externalOrderId, 'pdf');

            if ($filePath === null) {
                return ['status' => 'error', 'message' => 'Unable to store binary label.'];
            }

            return ['status' => 'success', 'label_file' => $filePath];
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            $this->logger->error('Unable to parse JSON label response.', $this->logContext + ['external_order_id' => $externalOrderId]);

            return ['status' => 'error', 'message' => 'Invalid label response format.'];
        }

        if (! empty($data['labelUrl']) && is_string($data['labelUrl'])) {
            return ['status' => 'success', 'label_url' => esc_url_raw($data['labelUrl'])];
        }

        if (! empty($data['labelBase64']) && is_string($data['labelBase64'])) {
            $binary = base64_decode($data['labelBase64'], true);

            if ($binary === false) {
                $this->logger->error('labelBase64 decoding failed.', $this->logContext + ['external_order_id' => $externalOrderId]);

                return ['status' => 'error', 'message' => 'Invalid base64 label payload.'];
            }

            $extension = ! empty($data['labelExtension']) ? sanitize_key((string) $data['labelExtension']) : 'pdf';
            $filePath = $this->storeBinaryLabel($binary, $externalOrderId, $extension);

            if ($filePath === null) {
                return ['status' => 'error', 'message' => 'Unable to store decoded label file.'];
            }

            return ['status' => 'success', 'label_file' => $filePath];
        }

        $this->logger->error('Label response did not include URL or binary payload.', $this->logContext + ['external_order_id' => $externalOrderId]);

        return ['status' => 'error', 'message' => 'Label payload missing URL or file.'];
    }

    /**
     * @return string|null Absolute file path.
     */
    private function storeBinaryLabel(string $binary, string $externalOrderId, string $extension): ?string
    {
        $uploadDir = wp_upload_dir();

        if (! empty($uploadDir['error'])) {
            $this->logger->error('Unable to retrieve upload directory.', $this->logContext + [
                'external_order_id' => $externalOrderId,
                'error' => $uploadDir['error'],
            ]);

            return null;
        }

        $targetDirectory = trailingslashit((string) $uploadDir['basedir']) . Activation::LABEL_SUBDIR . '/';

        if (! wp_mkdir_p($targetDirectory)) {
            $this->logger->error('Unable to create label directory.', $this->logContext + [
                'external_order_id' => $externalOrderId,
                'dir' => $targetDirectory,
            ]);

            return null;
        }

        $safeExt = preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        $safeExt = $safeExt !== '' ? $safeExt : 'pdf';
        $secureToken = wp_generate_password(20, false, false);
        $fileName = sanitize_file_name(sprintf('label-%s-%s.%s', preg_replace('/[^a-zA-Z0-9_-]/', '', $externalOrderId), $secureToken, $safeExt));
        $filePath = $targetDirectory . $fileName;

        $bytesWritten = file_put_contents($filePath, $binary);

        if ($bytesWritten === false) {
            $this->logger->error('Failed writing label file.', $this->logContext + [
                'external_order_id' => $externalOrderId,
                'file' => $filePath,
            ]);

            return null;
        }

        $this->logger->info('Label file stored successfully.', $this->logContext + [
            'external_order_id' => $externalOrderId,
            'file' => $filePath,
            'bytes' => $bytesWritten,
        ]);

        return $filePath;
    }
}
