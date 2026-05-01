<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use OctavaWMS\WooCommerce\WooOrderExtId;
use OctavaWMS\WooCommerce\WooOrderWeights;
use WC_Order;

class AdminLabelActions
{
    private LabelService $labelService;

    private LabelMetaBox $labelMetaBox;

    private LabelAjax $labelAjax;

    private BackendApiClient $apiClient;

    public function __construct(LabelService $labelService, LabelMetaBox $labelMetaBox, LabelAjax $labelAjax, BackendApiClient $apiClient)
    {
        $this->labelService = $labelService;
        $this->labelMetaBox = $labelMetaBox;
        $this->labelAjax = $labelAjax;
        $this->apiClient = $apiClient;
    }

    public function register(): void
    {
        add_filter('woocommerce_admin_order_actions', [$this, 'addGenerateLabelOrderAction'], 20, 2);
        add_filter('woocommerce_order_actions', [$this, 'addOrderEditScreenAction'], 20, 2);
        add_action('woocommerce_order_action_octavawms_generate_label', [$this, 'handleGenerateLabelFromOrderMetabox']);

        add_action('admin_action_octavawms_generate_label', [$this, 'handleGenerateLabelAction']);
        add_action('admin_post_octavawms_download_label', [$this, 'handleDownloadLabelAction']);

        $this->labelMetaBox->register();
        $this->labelAjax->register();
    }

    /**
     * @param array<string, mixed> $actions
     *
     * @return array<string, mixed>
     */
    public function addGenerateLabelOrderAction(array $actions, WC_Order $order): array
    {
        if (! current_user_can('edit_shop_orders')) {
            return $actions;
        }

        $orderId = (int) $order->get_id();
        $hasLabel = (bool) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true) || (bool) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);

        $actions['octavawms_generate_label'] = [
            'url' => wp_nonce_url(
                admin_url('admin.php?action=octavawms_generate_label&order_id=' . $orderId),
                'octavawms_generate_label_' . $orderId
            ),
            'name' => $hasLabel ? __('Re-generate Label', 'octavawms') : __('Generate Label', 'octavawms'),
            'action' => 'view octavawms-generate-label',
        ];

        return $actions;
    }

    /**
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function addOrderEditScreenAction(array $actions, ?WC_Order $order): array
    {
        if (! $order instanceof WC_Order || ! current_user_can('edit_shop_orders', $order->get_id())) {
            return $actions;
        }

        $hasLabel = (bool) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true)
            || (bool) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);

        $actions['octavawms_generate_label'] = $hasLabel
            ? __('Re-generate shipping label', 'octavawms')
            : __('Generate shipping label', 'octavawms');

        return $actions;
    }

    public function handleGenerateLabelFromOrderMetabox(WC_Order $order): void
    {
        if (! current_user_can('edit_shop_orders', $order->get_id())) {
            return;
        }

        $success = $this->executeLabelGeneration($order);

        if (! $success && class_exists(\WC_Admin_Meta_Boxes::class, false)) {
            \WC_Admin_Meta_Boxes::add_error(
                __('OctavaWMS could not generate a shipping label. See order notes.', 'octavawms')
            );
        }
    }

    public function handleGenerateLabelAction(): void
    {
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;

        if (! $orderId || ! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You are not allowed to generate labels.', 'octavawms'));
        }

        check_admin_referer('octavawms_generate_label_' . $orderId);

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'octavawms'));
        }

        $success = $this->executeLabelGeneration($order);
        wp_safe_redirect($this->orderEditUrl($orderId, $success ? 'success' : 'error'));
        exit;
    }

    /**
     * @return bool Whether label storage succeeded
     */
    private function executeLabelGeneration(WC_Order $order): bool
    {
        $externalOrderId = $this->resolveExtIdForLabelRequest($order);
        $backendOrder = null;
        foreach (WooOrderExtId::lookupCandidates($order) as $extId) {
            $found = $this->apiClient->findOrderByExtId($extId);
            if ($found !== null) {
                $backendOrder = $found;
                break;
            }
        }

        $weightRaw = WooOrderWeights::contentsWeightTotal($order);
        $weightUnit = (string) get_option('woocommerce_weight_unit', 'kg');
        $weightGrams = max(1, (int) round(WooOrderWeights::toGrams($weightRaw, $weightUnit)));

        $result = $this->labelService->requestLabel(
            $externalOrderId,
            $weightGrams,
            100,
            100,
            100,
            (int) $order->get_id(),
            $backendOrder,
            WooOrderExtId::lookupCandidates($order)
        );

        if ($result['status'] !== 'success') {
            $order->add_order_note(sprintf(
                __('OctavaWMS label generation failed: %s', 'octavawms'),
                $result['message'] ?? 'unknown error'
            ));
            $order->save();

            return false;
        }

        $orderId = (int) $order->get_id();

        if (! empty($result['label_url'])) {
            $order->update_meta_data(LabelService::ORDER_META_LABEL_URL, $result['label_url']);
            $order->delete_meta_data(LabelService::ORDER_META_LABEL_FILE);
        }

        if (! empty($result['label_file'])) {
            $order->update_meta_data(LabelService::ORDER_META_LABEL_FILE, $result['label_file']);
            $order->delete_meta_data(LabelService::ORDER_META_LABEL_URL);
        }

        $order->save();

        $downloadLink = $this->labelMetaBox->buildDownloadMarkup(
            $orderId,
            (string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true),
            (string) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true)
        );
        $order->add_order_note(
            'OctavaWMS label generated successfully. ' . wp_strip_all_tags($downloadLink)
        );
        $order->save();

        return true;
    }

    /**
     * Match {@see LabelAjax::resolveExtIdForLabelRequest} so order actions and the meta box use the same extId rules.
     */
    private function resolveExtIdForLabelRequest(WC_Order $order): string
    {
        foreach (WooOrderExtId::lookupCandidates($order) as $extId) {
            if ($this->apiClient->findOrderByExtId($extId) !== null) {
                return $extId;
            }
        }

        return WooOrderExtId::importFilterExtId($order);
    }

    public function handleDownloadLabelAction(): void
    {
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;

        if (! $orderId || ! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Unauthorized label download request.', 'octavawms'));
        }

        check_admin_referer('octavawms_download_label_' . $orderId);

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'octavawms'));
        }

        $filePath = (string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);
        if ($filePath === '' || ! file_exists($filePath) || ! is_readable($filePath)) {
            wp_die(esc_html__('Label file unavailable.', 'octavawms'));
        }

        $ext = self::fileExtension($filePath);
        $fileBase = 'order-' . (string) $orderId . '-label.' . $ext;

        $inline = isset($_GET['inline']) && (string) wp_unslash($_GET['inline']) === '1';

        $repaired = self::repairLabelFileIfBase64($filePath);
        $effectivePath = $repaired ?? $filePath;
        $mime = self::mimeTypeForFilePath($effectivePath);

        nocache_headers();
        header('Content-Type: ' . $mime);
        if ($inline) {
            header('Content-Disposition: inline; filename="' . $fileBase . '"');
        } else {
            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename="' . $fileBase . '"');
        }
        header('Content-Length: ' . (string) filesize($effectivePath));
        readfile($effectivePath);
        exit;
    }

    private function orderEditUrl(int $orderId, string $state): string
    {
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order && function_exists('wc_get_order_edit_url')) {
                return add_query_arg('octavawms_label', $state, (string) wc_get_order_edit_url($order->get_id()));
            }
        }

        return add_query_arg([
            'post' => $orderId,
            'action' => 'edit',
            'octavawms_label' => $state,
        ], admin_url('post.php'));
    }


    private static function fileExtension(string $filePath): string
    {
        $base = (string) pathinfo($filePath, PATHINFO_EXTENSION);
        $base = preg_replace('/[^a-z0-9]/i', '', $base) ?? '';

        return $base !== '' ? strtolower($base) : 'pdf';
    }

    /**
     * If a PDF label file was previously written as base64 text (older plugin versions),
     * decode it in-place and rewrite the file as real PDF bytes.
     *
     * Returns the path to use (same path on success / no-op, or null if the file is fine and untouched).
     */
    private static function repairLabelFileIfBase64(string $filePath): ?string
    {
        if (! is_readable($filePath)) {
            return null;
        }
        $head = (string) file_get_contents($filePath, false, null, 0, 8);
        if ($head === '' || str_starts_with($head, '%PDF')) {
            return null;
        }
        $raw = (string) file_get_contents($filePath);
        if ($raw === '') {
            return null;
        }

        $decoded = self::tryDecodeBase64Pdf($raw);
        if ($decoded === null) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $decoded = self::findPdfBytesInDecodedJson($json, 0);
            }
        }
        if ($decoded === null) {
            return null;
        }

        if (@file_put_contents($filePath, $decoded) === false) {
            $tmp = wp_tempnam('octavawms-label-repair');
            if ($tmp && @file_put_contents($tmp, $decoded) !== false) {
                return $tmp;
            }

            return null;
        }

        return $filePath;
    }

    private static function tryDecodeBase64Pdf(string $s): ?string
    {
        $compact = preg_replace('/\s+/', '', trim($s)) ?? '';
        $compact = preg_replace('/[^A-Za-z0-9+\/=_\-]/', '', $compact) ?? '';
        if ($compact === '') {
            return null;
        }
        $compact = strtr($compact, '-_', '+/');
        $compact = rtrim($compact, '=');
        $pad = (4 - strlen($compact) % 4) % 4;
        if ($pad === 1) {
            return null;
        }
        $bin = base64_decode($compact . str_repeat('=', $pad), true);
        if ($bin === false || $bin === '' || ! str_starts_with($bin, '%PDF')) {
            return null;
        }

        return $bin;
    }

    /**
     * @param array<mixed> $node
     */
    private static function findPdfBytesInDecodedJson(array $node, int $depth): ?string
    {
        if ($depth > 10) {
            return null;
        }
        $priority = ['pdfData', 'pdf', 'pdf_base64', 'pdfBase64', 'labelPdf', 'label_pdf', 'content', 'data', 'file', 'body'];
        foreach ($priority as $k) {
            if (isset($node[$k]) && is_string($node[$k])) {
                $cand = self::tryDecodeBase64Pdf($node[$k]);
                if ($cand !== null) {
                    return $cand;
                }
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $nested = self::findPdfBytesInDecodedJson($v, $depth + 1);
                if ($nested !== null) {
                    return $nested;
                }
            } elseif (is_string($v) && strlen($v) > 200) {
                $cand = self::tryDecodeBase64Pdf($v);
                if ($cand !== null) {
                    return $cand;
                }
            }
        }

        return null;
    }

    private static function mimeTypeForFilePath(string $filePath): string
    {
        $ext = self::fileExtension($filePath);
        $map = [
            'pdf' => 'application/pdf',
            'zpl' => 'text/plain',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
        ];

        if (class_exists('finfo') && is_readable($filePath)) {
            $f = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $f->file($filePath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $map[$ext] ?? 'application/octet-stream';
    }
}
