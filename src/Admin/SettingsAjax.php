<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Admin;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Options;

class SettingsAjax
{
    public const ACTION = 'octavawms_carrier_matrix';

    private BackendApiClient $apiClient;

    /** @var list<string> */
    private const ALLOWED_TYPES = ['address', 'office', 'locker', 'office_locker'];

    public function __construct(BackendApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Registers admin-ajax hooks without instantiating this class first, so a fatal during
     * BackendApiClient construction cannot prevent the hooks from existing.
     *
     * WordPress dispatches logged-out requests only to wp_ajax_nopriv_<action>.
     */
    public static function registerAjax(): void
    {
        if (! has_action('wp_ajax_' . self::ACTION)) {
            add_action('wp_ajax_' . self::ACTION, [self::class, 'dispatchAjax']);
        }
        if (! has_action('wp_ajax_nopriv_' . self::ACTION)) {
            add_action('wp_ajax_nopriv_' . self::ACTION, [self::class, 'dispatchAjax']);
        }
    }

    public static function dispatchAjax(): void
    {
        $api = new BackendApiClient();
        (new self($api))->handleAjax();
    }

    public function register(): void
    {
        self::registerAjax();
    }

    public function handleAjax(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission.', 'octavawms')], 403);
        }

        check_ajax_referer(self::ACTION, 'security');

        $sub = isset($_POST['subaction']) ? sanitize_key((string) wp_unslash($_POST['subaction'])) : '';
        if ($sub === 'get') {
            $this->handleGet();
        } elseif ($sub === 'save') {
            $this->handleSave();
        } elseif ($sub === 'integrations') {
            $this->handleIntegrations();
        } elseif ($sub === 'rates') {
            $this->handleRates();
        } elseif ($sub === 'meta_keys') {
            $this->handleMetaKeys();
        } else {
            wp_send_json_error(['message' => __('Invalid request.', 'octavawms')], 400);
        }
    }

    private function handleGet(): void
    {
        $sourceId = Options::getSourceId();
        if ($sourceId <= 0) {
            wp_send_json_error(['message' => __('Connect the store first (no source id).', 'octavawms')], 400);
        }
        $source = $this->apiClient->getIntegrationSource($sourceId);
        if ($source === null) {
            wp_send_json_error(['message' => __('Could not load integration source.', 'octavawms')], 502);
        }
        $settings = $source['settings'] ?? null;
        $mapping = [];
        if (is_array($settings)) {
            $ds = $settings['DeliveryServices'] ?? null;
            if (is_array($ds)) {
                $opts = $ds['options'] ?? null;
                if (is_array($opts) && isset($opts['carrierMapping'])) {
                    $cm = $opts['carrierMapping'];
                    if (is_array($cm)) {
                        $mapping = $cm;
                    } elseif (is_string($cm) && trim($cm) !== '') {
                        $decoded = json_decode($cm, true);
                        if (is_array($decoded)) {
                            $mapping = $decoded;
                        }
                    }
                }
            }
        }

        wp_send_json_success(['carrierMapping' => $mapping]);
    }

    private function handleSave(): void
    {
        $sourceId = Options::getSourceId();
        if ($sourceId <= 0) {
            wp_send_json_error(['message' => __('Connect the store first (no source id).', 'octavawms')], 400);
        }

        $raw = isset($_POST['carrier_mapping_json'])
            ? wp_unslash((string) $_POST['carrier_mapping_json'])
            : '';
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ($decoded !== [] && ! array_is_list($decoded))) {
            wp_send_json_error(['message' => __('carrierMapping must be a JSON array.', 'octavawms')], 400);
        }

        $nonEmpty = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $k = isset($row['courierMetaKey']) && is_string($row['courierMetaKey'])
                ? trim($row['courierMetaKey'])
                : '';
            $v = isset($row['courierMetaValue']) && is_string($row['courierMetaValue'])
                ? trim($row['courierMetaValue'])
                : '';
            if ($k === '' || $v === '') {
                continue;
            }
            $nonEmpty[] = $row;
        }

        $normalized = $this->validateAndNormalizeRows($nonEmpty);
        if ($normalized === null) {
            wp_send_json_error(['message' => __('Invalid mapping row(s).', 'octavawms')], 400);
        }

        $source = $this->apiClient->getIntegrationSource($sourceId);
        if ($source === null) {
            wp_send_json_error(['message' => __('Could not load integration source.', 'octavawms')], 502);
        }

        $settings = is_array($source['settings'] ?? null) ? $source['settings'] : [];
        if (! isset($settings['DeliveryServices']) || ! is_array($settings['DeliveryServices'])) {
            $settings['DeliveryServices'] = [];
        }
        if (! isset($settings['DeliveryServices']['options']) || ! is_array($settings['DeliveryServices']['options'])) {
            $settings['DeliveryServices']['options'] = [];
        }
        $settings['DeliveryServices']['options']['carrierMapping'] = $normalized;

        $patch = $this->apiClient->patchIntegrationSource($sourceId, ['settings' => $settings]);
        if (! $patch['ok']) {
            $msg = is_array($patch['data']) && isset($patch['data']['detail']) && is_string($patch['data']['detail'])
                ? $patch['data']['detail']
                : __('Could not save settings.', 'octavawms');
            wp_send_json_error(['message' => $msg], min(599, max(400, (int) $patch['status'])));
        }

        wp_send_json_success(['carrierMapping' => $normalized]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>|null
     */
    private function validateAndNormalizeRows(array $rows): ?array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }
            $k = isset($row['courierMetaKey']) && is_string($row['courierMetaKey'])
                ? trim($row['courierMetaKey'])
                : '';
            $v = isset($row['courierMetaValue']) && is_string($row['courierMetaValue'])
                ? trim($row['courierMetaValue'])
                : '';
            if ($k === '' || $v === '') {
                return null;
            }
            $wooDt = '';
            if (isset($row['wooDeliveryType']) && is_string($row['wooDeliveryType'])) {
                $wooDt = trim($row['wooDeliveryType']);
            }
            $type = isset($row['type']) && is_string($row['type']) ? trim($row['type']) : 'address';
            if (! in_array($type, self::ALLOWED_TYPES, true)) {
                return null;
            }
            $ds = $row['deliveryService'] ?? null;
            if (! is_numeric($ds) || (int) $ds <= 0) {
                return null;
            }
            $rate = null;
            if (array_key_exists('rate', $row)) {
                if ($row['rate'] === null || $row['rate'] === '') {
                    $rate = null;
                } elseif (is_numeric($row['rate'])) {
                    $rid = (int) $row['rate'];
                    $rate = $rid > 0 ? $rid : null;
                } else {
                    return null;
                }
            }

            $out[] = [
                'courierMetaKey' => $k,
                'courierMetaValue' => $v,
                'wooDeliveryType' => $wooDt,
                'type' => $type,
                'deliveryService' => (int) $ds,
                'rate' => $rate,
            ];
        }

        return $out;
    }

    private function handleIntegrations(): void
    {
        $search = isset($_POST['search']) ? sanitize_text_field((string) wp_unslash($_POST['search'])) : '';
        $page = isset($_POST['page']) ? max(1, absint(wp_unslash($_POST['page']))) : 1;
        $result = $this->apiClient->fetchDeliveryServiceIntegrationsPage($search, $page);
        $items = [];
        foreach ($result['items'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
            $embedded = $row['_embedded'] ?? null;
            $dsId = 0;
            if (is_array($embedded)) {
                $ds = $embedded['deliveryService'] ?? null;
                if (is_array($ds) && isset($ds['id']) && is_numeric($ds['id'])) {
                    $dsId = (int) $ds['id'];
                }
            }
            if ($id <= 0 || $dsId <= 0) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'text' => $name !== '' ? $name . ' [' . $id . ']' : (string) $id,
                'deliveryServiceId' => $dsId,
            ];
        }

        wp_send_json_success([
            'items' => $items,
            'total_pages' => $result['total_pages'],
        ]);
    }

    private function handleRates(): void
    {
        $dsId = isset($_POST['delivery_service_id']) ? absint(wp_unslash($_POST['delivery_service_id'])) : 0;
        if ($dsId <= 0) {
            wp_send_json_error(['message' => __('Invalid delivery service.', 'octavawms')], 400);
        }
        $rows = $this->apiClient->fetchRatesForDeliveryService($dsId);
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rid = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            if ($rid <= 0) {
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : (string) $rid;
            $items[] = [
                'id' => $rid,
                'text' => $name . ' [' . $rid . ']',
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    /**
     * Returns distinct public (non-underscore-prefixed) WooCommerce order meta keys.
     * Supports both HPOS (wc_orders_meta) and classic CPT (postmeta) storage.
     */
    private function handleMetaKeys(): void
    {
        $search = isset($_POST['search'])
            ? sanitize_text_field((string) wp_unslash($_POST['search']))
            : '';

        wp_send_json_success(['items' => $this->fetchOrderMetaKeys($search)]);
    }

    /**
     * @return list<string>
     */
    private function fetchOrderMetaKeys(string $search): array
    {
        global $wpdb;

        // Detect HPOS custom orders table (WooCommerce 7.1+ with HPOS enabled).
        $hposTable = $wpdb->prefix . 'wc_orders_meta';
        $useHpos   = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
                $hposTable
            )
        );

        // Build optional search clause (already escapes LIKE wildcards).
        $args = ['\_%']; // first placeholder: NOT LIKE '\_%' excludes _private keys
        $searchClause = '';
        if ($search !== '') {
            $searchClause = 'AND meta_key LIKE %s';
            $args[]       = '%' . $wpdb->esc_like($search) . '%';
        }

        if ($useHpos) {
            $sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT DISTINCT meta_key FROM `{$hposTable}` WHERE meta_key NOT LIKE %s {$searchClause} ORDER BY meta_key LIMIT 100",
                ...$args
            );
        } else {
            $sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                "SELECT DISTINCT pm.meta_key FROM `{$wpdb->postmeta}` pm
                 INNER JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id AND p.post_type = 'shop_order'
                 WHERE pm.meta_key NOT LIKE %s {$searchClause}
                 ORDER BY pm.meta_key LIMIT 100",
                ...$args
            );
        }

        $rows = $wpdb->get_col($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

        return is_array($rows)
            ? array_values(array_filter($rows, static fn ($k) => is_string($k) && $k !== ''))
            : [];
    }
}
