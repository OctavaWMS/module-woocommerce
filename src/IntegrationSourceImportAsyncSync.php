<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Api\BackendApiClient;

/**
 * Keeps OctavaWMS integration source {@see general} async-import flags aligned with the WooCommerce plugin
 * "Async import" checkbox (mirrors Orderadmin panel: "Asynchronous import of orders").
 *
 * Stored under {@code settings.general} using the Laminas form element name from the WooCommerce integration schema.
 */
final class IntegrationSourceImportAsyncSync
{
    /**
     * Matches {@code asyncImport[Orderadmin\Products\Entity\Order]} in Orderadmin integration settings form.
     */
    public const GENERAL_ASYNC_IMPORT_ORDER_ELEMENT = 'asyncImport[Orderadmin\\Products\\Entity\\Order]';

    /**
     * @param array<string, mixed> $settings Source {@code settings} from GET (partial allowed).
     *
     * @return array<string, mixed>
     */
    public static function mergeImportAsyncIntoSettings(array $settings, bool $importAsyncEnabled): array
    {
        if (! isset($settings['general']) || ! is_array($settings['general'])) {
            $settings['general'] = [];
        }
        $settings['general'][self::GENERAL_ASYNC_IMPORT_ORDER_ELEMENT] = $importAsyncEnabled ? 1 : 0;

        return $settings;
    }

    /**
     * GET source, merge async flag, PATCH. Does nothing useful when {@code $sourceId <= 0}.
     *
     * @return array{ok: bool, message: string}
     */
    public static function syncImportAsyncSetting(BackendApiClient $api, int $sourceId, bool $importAsyncEnabled): array
    {
        if ($sourceId <= 0) {
            return ['ok' => true, 'message' => ''];
        }

        $source = $api->getIntegrationSource($sourceId);
        if ($source === null) {
            return [
                'ok' => false,
                'message' => __('Could not load integration source from OctavaWMS.', 'octavawms'),
            ];
        }

        $settings = is_array($source['settings'] ?? null) ? $source['settings'] : [];
        $merged = self::mergeImportAsyncIntoSettings($settings, $importAsyncEnabled);
        $patch = $api->patchIntegrationSource($sourceId, ['settings' => $merged]);
        if ($patch['ok']) {
            return ['ok' => true, 'message' => ''];
        }

        $msg = __('Could not save async import setting on OctavaWMS.', 'octavawms');
        if (is_array($patch['data']) && isset($patch['data']['detail']) && is_string($patch['data']['detail'])) {
            $msg = $patch['data']['detail'];
        } elseif (is_string($patch['raw'] ?? null) && $patch['raw'] !== '') {
            $msg = mb_substr((string) $patch['raw'], 0, 500);
        }

        return ['ok' => false, 'message' => $msg];
    }
}
