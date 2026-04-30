<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\Api\LabelService;
use WC_Order;

class AdminLabelActions
{
    private LabelService $labelService;

    private LabelMetaBox $labelMetaBox;

    private LabelAjax $labelAjax;

    public function __construct(LabelService $labelService, LabelMetaBox $labelMetaBox, LabelAjax $labelAjax)
    {
        $this->labelService = $labelService;
        $this->labelMetaBox = $labelMetaBox;
        $this->labelAjax = $labelAjax;
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
        $externalOrderId = (string) $order->get_meta('_octavawms_external_order_id', true);
        if ($externalOrderId === '') {
            $externalOrderId = (string) $order->get_order_key();
        }

        $weightRaw = (float) $order->get_total_weight();
        $weightUnit = (string) get_option('woocommerce_weight_unit', 'kg');
        $weightGrams = max(1, (int) round(self::convertOrderWeightToGrams($weightRaw, $weightUnit)));

        $result = $this->labelService->requestLabel($externalOrderId, $weightGrams, 100, 100, 100, (int) $order->get_id());

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

        $mime = self::mimeTypeForFilePath($filePath);
        $ext = self::fileExtension($filePath);
        $fileBase = 'order-' . (string) $orderId . '-label.' . $ext;

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $fileBase . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
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

    /**
     * Match LabelAjax: convert store weight unit to grams for preprocessing-task payload.
     */
    private static function convertOrderWeightToGrams(float $weight, string $unit): float
    {
        return match (strtolower($unit)) {
            'kg' => $weight * 1000.0,
            'lbs' => $weight * 453.592,
            'oz' => $weight * 28.3495,
            default => $weight,
        };
    }

    private static function fileExtension(string $filePath): string
    {
        $base = (string) pathinfo($filePath, PATHINFO_EXTENSION);
        $base = preg_replace('/[^a-z0-9]/i', '', $base) ?? '';

        return $base !== '' ? strtolower($base) : 'pdf';
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
