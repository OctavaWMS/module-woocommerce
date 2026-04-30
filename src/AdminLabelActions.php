<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use WC_Order;
use WP_Post;

class AdminLabelActions
{
    private LabelService $labelService;

    public function __construct(LabelService $labelService)
    {
        $this->labelService = $labelService;
    }

    public function register(): void
    {
        // Order **list** table action buttons / row icons (Orders screen).
        add_filter('woocommerce_admin_order_actions', [$this, 'addGenerateLabelOrderAction'], 20, 2);

        // Order **edit** screen: sidebar metabox dropdown "Choose an action…" → Apply.
        add_filter('woocommerce_order_actions', [$this, 'addOrderEditScreenAction'], 20, 2);
        add_action('woocommerce_order_action_octavawms_generate_label', [$this, 'handleGenerateLabelFromOrderMetabox']);

        add_action('admin_action_octavawms_generate_label', [$this, 'handleGenerateLabelAction']);
        add_action('admin_post_octavawms_download_label', [$this, 'handleDownloadLabelAction']);
        add_action('add_meta_boxes', [$this, 'registerMetaBox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueOrderPageStyles']);
    }

    /**
     * Registers the order label meta box (classic `shop_order` and HPOS `woocommerce_page_wc-orders`).
     *
     * @param string|\WP_Screen $postTypeOrScreen Screen id or post type (first arg from `add_meta_boxes`).
     * @param WC_Order|WP_Post|null $postOrOrder Post or order object when available.
     */
    public function registerMetaBox($_postTypeOrScreen = null, $_postOrOrder = null): void
    {
        add_meta_box(
            'octavawms-label',
            __('OctavaWMS Connector', 'octavawms'),
            [$this, 'renderLabelMetaBox'],
            ['shop_order', 'woocommerce_page_wc-orders'],
            'normal',
            'default'
        );
    }

    public function enqueueOrderPageStyles(string $hook): void
    {
        if (! $this->isOrderEditAdminScreen()) {
            return;
        }

        $css = <<<'CSS'
.octavawms-label-box{padding:4px 0 8px;}
.octavawms-label-box__section{margin-bottom:12px;}
.octavawms-label-box__section:last-child{margin-bottom:0;}
.octavawms-notice{padding:10px 12px;margin:0 0 12px;border-left:4px solid #2271b1;background:#f0f6fc;border-radius:2px;font-size:13px;line-height:1.5;}
.octavawms-notice--success{border-left-color:#1e8734;background:#edfaef;}
.octavawms-notice--error{border-left-color:#cc1818;background:#fcf0f1;}
.octavawms-notice--info{border-left-color:#2271b1;background:#f0f6fc;}
.octavawms-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;line-height:1.6;margin-bottom:10px;}
.octavawms-badge--success{background:#edfaef;color:#1e8734;border:1px solid #b8e6bf;}
.octavawms-badge--info{background:#f0f6fc;color:#1d2327;border:1px solid #c3d9ed;}
.octavawms-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:4px;}
.octavawms-actions .button{min-height:30px;}
CSS;

        $handle = 'woocommerce_admin_styles';
        if (wp_style_is($handle, 'registered')) {
            wp_enqueue_style($handle);
        } else {
            wp_register_style('octavawms-order-label-ui', false, [], '1.0.0');
            wp_enqueue_style('octavawms-order-label-ui');
            $handle = 'octavawms-order-label-ui';
        }

        wp_add_inline_style($handle, $css);
    }

    private function isOrderEditAdminScreen(): bool
    {
        if (! is_admin()) {
            return false;
        }

        global $pagenow;

        if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
            $postId = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
            $postType = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';

            if ($pagenow === 'post-new.php' && $postType === 'shop_order') {
                return true;
            }

            if ($pagenow === 'post.php' && $postId > 0 && function_exists('get_post_type')) {
                return get_post_type($postId) === 'shop_order';
            }

            return false;
        }

        if ($pagenow === 'admin.php'
            && isset($_GET['page'], $_GET['action'])
            && sanitize_key(wp_unslash((string) $_GET['page'])) === 'wc-orders'
            && sanitize_key(wp_unslash((string) $_GET['action'])) === 'edit'
            && isset($_GET['id'])
            && absint(wp_unslash($_GET['id'])) > 0
        ) {
            return true;
        }

        return false;
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
     * @param array<string, string>          $actions
     * @param \WC_Order|null $order
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

    /**
     * Runs when merchant picks our action under "Order actions" on the edit order screen and clicks Update.
     */
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

        $result = $this->labelService->requestLabel($externalOrderId);

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

        $downloadLink = $this->buildDownloadMarkup(
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
     * @param WC_Order|WP_Post $postOrOrder
     * @param array<string, mixed>|null $metaBox
     */
    public function renderLabelMetaBox($postOrOrder, $metaBox = null): void
    {
        $order = $this->resolveOrderFromMetaBoxArg($postOrOrder);
        if (! $order instanceof WC_Order) {
            return;
        }

        if (! current_user_can('edit_shop_orders', $order->get_id())) {
            return;
        }

        $orderId = (int) $order->get_id();
        $labelUrl = (string) $order->get_meta(LabelService::ORDER_META_LABEL_URL, true);
        $labelFile = (string) $order->get_meta(LabelService::ORDER_META_LABEL_FILE, true);
        $hasLabel = ($labelUrl !== '' || $labelFile !== '');

        echo '<div class="octavawms-label-box">';

        $flash = $this->getLabelFlashMessage();
        if ($flash !== null) {
            $tone = $flash['type'] === 'success' ? 'success' : 'error';
            echo '<div class="octavawms-label-box__section">';
            echo '<p class="octavawms-notice octavawms-notice--' . esc_attr($tone) . '">';
            echo esc_html($flash['message']);
            echo '</p>';
            echo '</div>';
        }

        if (! $hasLabel) {
            echo '<div class="octavawms-label-box__section">';
            echo '<p class="octavawms-notice octavawms-notice--info">';
            echo esc_html__(
                'No label generated yet for this order. Generate one below, or use Order actions → Generate shipping label, then Update.',
                'octavawms'
            );
            echo '</p>';
            echo '<div class="octavawms-actions">';
            printf(
                '<a class="button button-primary" href="%s">%s</a>',
                esc_url($this->buildGenerateLabelUrl($orderId)),
                esc_html__('Generate Label', 'octavawms')
            );
            echo '</div>';
            echo '</div>';
            echo '</div>';

            return;
        }

        echo '<div class="octavawms-label-box__section">';
        echo '<span class="octavawms-badge octavawms-badge--success">' . esc_html__('Label Ready', 'octavawms') . '</span>';
        echo '<div class="octavawms-actions">';
        echo wp_kses(
            $this->buildDownloadMarkup($orderId, $labelFile, $labelUrl, 'button button-primary'),
            [
                'a' => [
                    'href' => true,
                    'class' => true,
                    'target' => true,
                    'rel' => true,
                ],
            ]
        );
        printf(
            '<a class="button" href="%s">%s</a>',
            esc_url($this->buildGenerateLabelUrl($orderId)),
            esc_html__('Re-generate Label', 'octavawms')
        );
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * @param WC_Order|WP_Post $postOrOrder
     */
    private function resolveOrderFromMetaBoxArg($postOrOrder): ?WC_Order
    {
        if ($postOrOrder instanceof WC_Order) {
            return $postOrOrder;
        }

        if ($postOrOrder instanceof WP_Post && $postOrOrder->post_type === 'shop_order') {
            $order = wc_get_order($postOrOrder->ID);

            return $order instanceof WC_Order ? $order : null;
        }

        return null;
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function getLabelFlashMessage(): ?array
    {
        if (! isset($_GET['octavawms_label'])) {
            return null;
        }

        $raw = sanitize_key(wp_unslash((string) $_GET['octavawms_label']));
        if ($raw === 'success') {
            return [
                'type' => 'success',
                'message' => __('Label generated successfully.', 'octavawms'),
            ];
        }

        if ($raw === 'error') {
            return [
                'type' => 'error',
                'message' => __('Label generation failed. See order notes for details.', 'octavawms'),
            ];
        }

        return null;
    }

    private function buildGenerateLabelUrl(int $orderId): string
    {
        return wp_nonce_url(
            admin_url('admin.php?action=octavawms_generate_label&order_id=' . (string) $orderId),
            'octavawms_generate_label_' . (string) $orderId
        );
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

    private function buildDownloadMarkup(int $orderId, string $labelFile, string $labelUrl, string $anchorClass = ''): string
    {
        $classAttr = $anchorClass !== '' ? ' class="' . esc_attr($anchorClass) . '"' : '';

        if ($labelUrl !== '') {
            return sprintf(
                '<a%s href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                $classAttr,
                esc_url($labelUrl),
                esc_html__('Download Label', 'octavawms')
            );
        }

        if ($labelFile !== '') {
            $downloadUrl = wp_nonce_url(
                admin_url('admin-post.php?action=octavawms_download_label&order_id=' . (string) $orderId),
                'octavawms_download_label_' . (string) $orderId
            );

            return sprintf(
                '<a%s href="%s">%s</a>',
                $classAttr,
                esc_url($downloadUrl),
                esc_html__('Download Label', 'octavawms')
            );
        }

        return '';
    }
}
