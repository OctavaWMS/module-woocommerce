<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Admin;

use WC_Order;
use WP_Post;

class LabelMetaBox
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetaBox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueOrderPageAssets']);
    }

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

    public function enqueueOrderPageAssets(string $hook): void
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
.octavawms-badge--error{background:#fcf0f1;color:#cc1818;border:1px solid #f0b8bc;}
.octavawms-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:4px;}
.octavawms-actions .button{min-height:30px;}
.octavawms-spinner{display:inline-block;width:16px;height:16px;border:2px solid #ccc;border-top-color:#2271b1;border-radius:50%;animation:octava-spin 0.7s linear infinite;vertical-align:middle;margin-right:6px;}
@keyframes octava-spin{to{transform:rotate(360deg);}}
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

        wp_register_script('octavawms-order-panel', false, [], '1.0.0', true);
        wp_enqueue_script('octavawms-order-panel');

        $orderId = $this->resolveOrderIdForScreen();
        if ($orderId <= 0) {
            return;
        }

        wp_localize_script('octavawms-order-panel', 'octavawmsOrderPanel', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'orderId' => $orderId,
            'statusNonce' => wp_create_nonce('octavawms_order_status_' . (string) $orderId),
            'uploadNonce' => wp_create_nonce('octavawms_upload_order_' . (string) $orderId),
            'generateLabelNonce' => wp_create_nonce('octavawms_generate_label_' . (string) $orderId),
            'strings' => [
                'loading' => __('Loading…', 'octavawms'),
                'error' => __('Could not load OctavaWMS status.', 'octavawms'),
                'noOrder' => __('This order is not in OctavaWMS yet. Upload it to create shipments and labels.', 'octavawms'),
                'uploadOrder' => __('Upload order', 'octavawms'),
                'uploading' => __('Uploading…', 'octavawms'),
                'orderSynced' => __('Order synced', 'octavawms'),
                'awaitingShipment' => __('Order is in OctavaWMS; waiting for a shipment (delivery request) to appear.', 'octavawms'),
                'shipment' => __('Shipment', 'octavawms'),
                'labelReady' => __('Label Ready', 'octavawms'),
                'downloadLabel' => __('Download Label', 'octavawms'),
                'generateLabel' => __('Generate Label', 'octavawms'),
                'regenerateLabel' => __('Re-generate Label', 'octavawms'),
                'generatingLabel' => __('Generating label…', 'octavawms'),
                'tryAgain' => __('Try again', 'octavawms'),
            ],
        ]);

        wp_add_inline_script('octavawms-order-panel', $this->inlinePanelScript(), 'after');
    }

    private function resolveOrderIdForScreen(): int
    {
        global $pagenow;
        if ($pagenow === 'admin.php'
            && isset($_GET['page'], $_GET['action'], $_GET['id'])
            && sanitize_key(wp_unslash((string) $_GET['page'])) === 'wc-orders'
            && sanitize_key(wp_unslash((string) $_GET['action'])) === 'edit'
        ) {
            return absint(wp_unslash($_GET['id']));
        }

        if ($pagenow === 'post.php' && isset($_GET['post'])) {
            $postId = absint(wp_unslash($_GET['post']));
            if ($postId > 0 && function_exists('get_post_type') && get_post_type($postId) === 'shop_order') {
                return $postId;
            }
        }

        return 0;
    }

    private function inlinePanelScript(): string
    {
        return <<<'JS'
(function(){
const root=document.getElementById("octavawms-panel");
if(!root||typeof octavawmsOrderPanel==="undefined"){return;}
const cfg=octavawmsOrderPanel;
function esc(s){const d=document.createElement("div");d.textContent=s;return d.innerHTML;}
function hrefAttr(u){return String(u||"").replace(/&/g,"&amp;").replace(/"/g,"&quot;");}
function renderError(msg){
root.innerHTML="<div class=\"octavawms-label-box__section\"><p class=\"octavawms-notice octavawms-notice--error\">"+esc(msg)+"</p><div class=\"octavawms-actions\"><button type=\"button\" class=\"button button-primary\" id=\"octavawms-retry\">"+esc(cfg.strings.tryAgain)+"</button></div></div>";
const retry=document.getElementById("octavawms-retry");
if(retry){retry.addEventListener("click",fetchStatus);}
}
function renderPanel(data){
const hasOrder=data.has_order;
const shipment=data.shipment;
const hasLocal=data.has_label_locally;
const dl=data.download_url||"";
let html="";
if(!hasOrder){
html+="<div class=\"octavawms-label-box__section\"><p class=\"octavawms-notice octavawms-notice--info\">"+esc(cfg.strings.noOrder)+"</p>";
html+="<div class=\"octavawms-actions\"><button type=\"button\" class=\"button button-primary\" id=\"octavawms-upload-order\">"+esc(cfg.strings.uploadOrder)+"</button></div></div>";
root.innerHTML=html;
document.getElementById("octavawms-upload-order").addEventListener("click",uploadOrder);
return;
}
if(!shipment||!shipment.id){
html+="<div class=\"octavawms-label-box__section\"><span class=\"octavawms-badge octavawms-badge--info\">"+esc(cfg.strings.orderSynced)+"</span>";
html+="<p class=\"description\">"+esc(cfg.strings.awaitingShipment)+"</p></div>";
root.innerHTML=html;
return;
}
const state=shipment.state||"";
const bad=["pending_error","error"].indexOf(state)!==-1;
html+="<div class=\"octavawms-label-box__section\"><p class=\"description\"><strong>"+esc(cfg.strings.shipment)+"</strong> #"+esc(String(shipment.id))+" <span class=\"octavawms-badge "+(bad?"octavawms-badge--error":"octavawms-badge--info")+"\">"+esc(state)+"</span></p>";
if(hasLocal&&dl){
html+="<span class=\"octavawms-badge octavawms-badge--success\">"+esc(cfg.strings.labelReady)+"</span>";
html+="<div class=\"octavawms-actions\"><a class=\"button button-primary\" href=\""+hrefAttr(dl)+"\">"+esc(cfg.strings.downloadLabel)+"</a>";
html+="<button type=\"button\" class=\"button octavawms-generate-label\">"+esc(cfg.strings.regenerateLabel)+"</button></div>";
}else{
html+="<div class=\"octavawms-actions\"><button type=\"button\" class=\"button button-primary octavawms-generate-label\">"+esc(cfg.strings.generateLabel)+"</button></div>";
}
html+="</div>";
root.innerHTML=html;
root.querySelectorAll(".octavawms-generate-label").forEach(function(btn){btn.addEventListener("click",generateLabel);});
}
function fetchStatus(){
root.innerHTML="<span class=\"octavawms-spinner\"></span> "+esc(cfg.strings.loading);
const body=new URLSearchParams();
body.set("action","octavawms_order_status");
body.set("nonce",cfg.statusNonce);
body.set("order_id",String(cfg.orderId));
fetch(cfg.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body,credentials:"same-origin"})
.then(function(r){return r.json();})
.then(function(j){if(!j||!j.success){renderError((j&&j.data&&j.data.message)||cfg.strings.error);return;}renderPanel(j.data);})
.catch(function(){renderError(cfg.strings.error);});
}
function generateLabel(){
root.innerHTML="<span class=\"octavawms-spinner\"></span> "+esc(cfg.strings.generatingLabel);
const body=new URLSearchParams();
body.set("action","octavawms_generate_label");
body.set("nonce",cfg.generateLabelNonce);
body.set("order_id",String(cfg.orderId));
fetch(cfg.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body,credentials:"same-origin"})
.then(function(r){return r.json();})
.then(function(j){if(!j||!j.success){renderError((j&&j.data&&j.data.message)||cfg.strings.error);return;}fetchStatus();})
.catch(function(){renderError(cfg.strings.error);});
}
function uploadOrder(){
root.innerHTML="<span class=\"octavawms-spinner\"></span> "+esc(cfg.strings.uploading);
const body=new URLSearchParams();
body.set("action","octavawms_upload_order");
body.set("nonce",cfg.uploadNonce);
body.set("order_id",String(cfg.orderId));
fetch(cfg.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body,credentials:"same-origin"})
.then(function(r){return r.json();})
.then(function(j){if(!j||!j.success){renderError((j&&j.data&&j.data.message)||cfg.strings.error);return;}fetchStatus();})
.catch(function(){renderError(cfg.strings.error);});
}
fetchStatus();
})();
JS;
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

        echo '<div id="octavawms-panel" class="octavawms-label-box__section" data-order-id="' . esc_attr((string) $orderId) . '">';
        echo '<span class="octavawms-spinner"></span> ';
        echo esc_html__('Loading…', 'octavawms');
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

    public function buildGenerateLabelUrl(int $orderId): string
    {
        return wp_nonce_url(
            admin_url('admin.php?action=octavawms_generate_label&order_id=' . (string) $orderId),
            'octavawms_generate_label_' . (string) $orderId
        );
    }

    public function buildDownloadMarkup(int $orderId, string $labelFile, string $labelUrl, string $anchorClass = ''): string
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
}
