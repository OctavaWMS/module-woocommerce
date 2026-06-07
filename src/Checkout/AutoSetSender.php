<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Checkout;

use OctavaWMS\WooCommerce\Admin\SettingsAjax;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\PluginLog;

use function is_array;
use function is_numeric;

/**
 * When integration source has no Default sender, resolve from the account's active senders.
 * If exactly one active sender exists, persist it to the source (first need) and use it.
 */
final class AutoSetSender
{
    public function __construct(private readonly BackendApiClient $apiClient)
    {
    }

    /**
     * @param array<string, mixed>|null $source Integration source from {@see BackendApiClient::getIntegrationSource()}.
     *
     * @return array{sender_id: int, outcome: string, sender_count?: int, message?: string, diagnostics?: array<string, mixed>}
     */
    public function resolve(int $sourceId, ?array $source, int $configuredSender = 0): array
    {
        if ($configuredSender > 0) {
            return ['sender_id' => $configuredSender, 'outcome' => 'configured'];
        }

        if ($sourceId <= 0) {
            return ['sender_id' => 0, 'outcome' => 'no_source_id'];
        }

        $preview = $this->apiClient->fetchActiveSendersPreview(2);
        $diagnostics = is_array($preview['diagnostics'] ?? null) ? $preview['diagnostics'] : [];
        $items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
        $count = count($items);
        $hasMore = (bool) ($preview['has_more'] ?? false);
        $message = isset($preview['message']) && is_string($preview['message']) ? trim($preview['message']) : '';

        if ($count === 0) {
            $outcome = ($preview['ok'] ?? false) ? 'no_active_senders' : 'senders_api_error';

            return $this->failureResult($sourceId, $outcome, 0, $message, $diagnostics);
        }

        if ($count > 1 || $hasMore) {
            return $this->failureResult(
                $sourceId,
                'multiple_senders',
                $hasMore ? max(2, $count) : $count,
                $message,
                $diagnostics
            );
        }

        $row = $items[0];
        $senderId = is_array($row) && isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        if ($senderId <= 0) {
            return $this->failureResult(
                $sourceId,
                'no_active_senders',
                0,
                $message !== '' ? $message : 'Sender row had no numeric id.',
                $diagnostics + ['parse_note' => 'sender_row_missing_id', 'sender_row' => $row]
            );
        }

        if (! $this->persistSender($sourceId, $source, $senderId)) {
            return ['sender_id' => 0, 'outcome' => 'persist_failed', 'sender_count' => 1];
        }

        PluginLog::log('info', 'autoset_sender', [
            'source_id' => $sourceId,
            'sender_id' => $senderId,
            'sender_count' => 1,
            'note' => 'Persisted Default sender to integration source on first checkout need.',
        ]);

        return ['sender_id' => $senderId, 'outcome' => 'auto_set', 'sender_count' => 1];
    }

    /**
     * @param array<string, mixed>|null $source
     */
    private function persistSender(int $sourceId, ?array $source, int $senderId): bool
    {
        if ($source === null) {
            $source = $this->apiClient->getIntegrationSource($sourceId);
        }
        if ($source === null) {
            PluginLog::log('warning', 'autoset_sender', [
                'source_id' => $sourceId,
                'sender_id' => $senderId,
                'note' => 'Could not load integration source before persisting sender.',
            ]);

            return false;
        }

        $settings = is_array($source['settings'] ?? null) ? $source['settings'] : [];
        $settings = SettingsAjax::mergeSenderIntoSettings($settings, $senderId);
        $patch = $this->apiClient->patchIntegrationSource($sourceId, ['settings' => $settings]);
        if (! ($patch['ok'] ?? false)) {
            PluginLog::log('warning', 'autoset_sender', [
                'source_id' => $sourceId,
                'sender_id' => $senderId,
                'note' => 'patch_integration_source_failed',
                'request' => is_array($patch['request'] ?? null) ? $patch['request'] : null,
            ] + PluginLog::responseFromFetched(
                (int) ($patch['status'] ?? 0),
                is_array($patch['response_headers'] ?? null) ? $patch['response_headers'] : [],
                (string) ($patch['raw'] ?? ''),
                is_array($patch['data'] ?? null) ? $patch['data'] : null
            ));

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $diagnostics
     *
     * @return array{sender_id: int, outcome: string, sender_count: int, message?: string, diagnostics: array<string, mixed>}
     */
    private function failureResult(
        int $sourceId,
        string $outcome,
        int $senderCount,
        string $message,
        array $diagnostics
    ): array {
        PluginLog::log('warning', 'autoset_sender', [
            'source_id' => $sourceId,
            'outcome' => $outcome,
            'sender_count' => $senderCount,
            'message' => $message !== '' ? $message : null,
        ] + $diagnostics);

        $result = [
            'sender_id' => 0,
            'outcome' => $outcome,
            'sender_count' => $senderCount,
            'diagnostics' => $diagnostics,
        ];
        if ($message !== '') {
            $result['message'] = $message;
        }

        return $result;
    }
}
