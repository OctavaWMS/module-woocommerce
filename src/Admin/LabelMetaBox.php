<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Admin;

use OctavaWMS\WooCommerce\ConnectService;
use OctavaWMS\WooCommerce\UiBranding;
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
            UiBranding::integrationTitle(),
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
.octavawms-label-box{padding:0 0 4px;}
.octavawms-connect-page{max-width:none;}
.octavawms-connect-toolbar{margin:0 0 24px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px 16px;width:100%;box-sizing:border-box;}
.octavawms-connect-toolbar__left{flex:1 1 200px;min-width:0;max-width:100%;}
.octavawms-connect-toolbar__left:empty{display:none;}
.octavawms-connect-toolbar__actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;flex:0 0 auto;margin-left:auto;}
.octavawms-shipment-state-banner{margin:0 0 16px;padding:12px 14px;border:1px solid #f0a9ae;background:#fcf0f1;border-radius:4px;box-sizing:border-box;width:100%;}
.octavawms-shipment-state-banner__status{margin:0 0 8px;display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;}
.octavawms-shipment-state-banner__extra{font-size:13px;font-weight:400;color:#646970;}
.octavawms-shipment-state-banner__message{margin:0;font-size:13px;line-height:1.5;color:#50575e;}
.octavawms-shipment-state-banner__actions{margin:12px 0 0;padding:0;}
.octavawms-connect-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);column-gap:24px;row-gap:24px;align-items:start;}
.octavawms-slot--label{grid-column:1;grid-row:1/span 2;}
.octavawms-slot--sp{grid-column:2;grid-row:1/span 2;align-self:start;}
@media(max-width:782px){
.octavawms-connect-grid{grid-template-columns:minmax(0,1fr);row-gap:24px;}
.octavawms-slot--label{grid-column:1;grid-row:1;}
.octavawms-slot--sp{grid-column:1;grid-row:2;grid-row-end:auto;}
}
@media(min-width:783px){
.octavawms-slot--sp{position:sticky;top:42px;}
}
.octavawms-connect-section{margin:0;}
.octavawms-connect-section-body{padding:14px 0 0;margin:0;position:relative;}
.octavawms-panel-label.is-loading .octavawms-connect-section-body{opacity:.55;pointer-events:none;}
.octavawms-section-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px 16px;margin:0 0 14px;padding:0;border:0;background:transparent;}
.octavawms-section-head--split{border-bottom:none;}
.octavawms-section-head--create-label{align-items:flex-start;}
.octavawms-create-label-heading{display:flex;flex-direction:column;align-items:flex-start;gap:8px;width:100%;min-width:0;}
.octavawms-connect-section-title{flex:0 1 auto;min-width:0;margin:0;padding:0;border:0;background:transparent;font-size:14px;line-height:1.35;font-weight:600;}
.octavawms-create-label-shipment-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;width:100%;}
.octavawms-create-label-shipment-ref{font-size:13px;line-height:1.45;color:#50575e;margin:0;}
.octavawms-create-label-shipment-meta .octavawms-label-shipment__badges{justify-content:flex-start;margin-top:0;}
.octavawms-numgrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 16px;margin:0 0 16px;padding:0;width:100%;box-sizing:border-box;}
.octavawms-numgrid__cell{display:flex;flex-direction:column;margin:0;gap:6px;padding:0;min-width:0;}
.octavawms-numgrid__cell label{font-size:13px;font-weight:600;margin:0;}
.octavawms-numgrid__control,input.octavawms-numgrid__control{border:1px solid #8c8f94;border-radius:0;box-sizing:border-box;width:100%;height:38px;line-height:normal;padding:0 10px;margin:0;font-size:13px;text-align:right;}
.octavawms-numgrid__control:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;outline:2px solid transparent;}
.octavawms-label-extra{margin:0 0 12px;padding:0;}
.octavawms-actions-row{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;align-items:center;margin:12px 0 0;padding:0;width:100%;box-sizing:border-box;}
.octavawms-toolbar-inline-group{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.octavawms-sp-card{background:#fdfdfd;border:1px solid #c3c4c7;border-radius:1px;padding:14px 16px;margin:0;width:100%;box-sizing:border-box;box-shadow:0 1px 1px rgba(0,0,0,.04);}
.octavawms-sp-card__title{font-size:14px;line-height:1.35;margin:0 0 4px;font-weight:600;}
.octavawms-sp-card__subtitle{font-size:12px;line-height:1.4;color:#646970;margin:0 0 12px;font-weight:400;}
.octavawms-sp-card__footer{margin-top:12px;display:flex;flex-direction:column;gap:10px;width:100%;}
.octavawms-sp-card__footer .button.button-primary{width:100%;justify-content:center;box-sizing:border-box;}
.octavawms-sp-search-row{display:flex;flex-wrap:nowrap;gap:12px;align-items:stretch;margin:0 0 12px;width:100%;box-sizing:border-box;}
.octavawms-sp-search-row__field{flex:0 0 calc(70% - 6px);min-width:0;}
.octavawms-sp-search-row__btn{flex:0 0 calc(30% - 6px);display:flex;align-items:center;justify-content:flex-end;}
.octavawms-sp-search-row__btn .button{width:100%;justify-content:center;box-sizing:border-box;}
.octavawms-sp-search-row__field input.regular-text{width:100%;max-width:none;}
.octavawms-notice{padding:10px 12px;margin:0 0 12px;border-left:4px solid #2271b1;background:#f0f6fc;font-size:13px;line-height:1.5;max-width:100%;box-sizing:border-box;}
.octavawms-notice--success{border-left-color:#1e8734;background:#edfaef;}
.octavawms-notice--error{border-left-color:#d63638;background:#fcf0f1;}
.octavawms-notice--info{border-left-color:#2271b1;background:#f0f6fc;}
.octavawms-sp-preview{margin:12px 0 0;padding:10px 0 0;border:0;border-top:1px solid #dcdcde;background:transparent;max-width:100%;box-sizing:border-box;}
.octavawms-sp-preview.is-empty{color:#646970;font-style:italic;}
.octavawms-sp-preview__head{margin:0 0 6px;font-size:13px;}
.octavawms-sp-preview__line{margin:0 0 4px;font-size:13px;line-height:1.45;color:#50575e;}
.octavawms-sp-preview__title{margin:0 0 8px;font-size:14px;line-height:1.35;font-weight:600;color:#1d2327;}
.octavawms-sp-preview__k{font-weight:600;margin-right:6px;color:#50575e;}
.octavawms-sp-preview__actions{margin:10px 0 0;padding:0;}
.octavawms-sp-inline-status{margin:0;min-height:1.35em;font-size:13px;}
.octavawms-meta--danger{display:inline;color:#b32d2e;font-weight:600;margin:0;padding:0;background:none;border:none;font-style:normal;}
.octavawms-meta--ok{display:inline;color:#1e8734;font-weight:600;margin:0;padding:0;background:none;border:none;font-style:normal;}
.octavawms-label-shipment__badges{display:flex;flex-wrap:wrap;justify-content:flex-end;align-items:center;gap:6px;margin-top:4px;}
.octavawms-label-shipment__pm{display:block;width:100%;flex-basis:100%;text-align:right;margin:2px 0 0;font-size:12px;line-height:1.35;color:#646970;}
.octavawms-status-pill{display:inline-flex;align-items:center;max-width:100%;margin:0;padding:2px 9px;font-size:12px;line-height:1.45;font-weight:600;border-radius:3px;box-sizing:border-box;font-style:normal;white-space:normal;text-align:center;}
.octavawms-status-pill--neutral{background:#f6f7f7;color:#50575f;border:1px solid #c3c4c7;}
.octavawms-status-pill--success{background:#edfaef;color:#1e4620;border:1px solid #68de7c;}
.octavawms-status-pill--warn{background:#fcf9e8;color:#734200;border:1px solid #dba617;}
.octavawms-status-pill--error{background:#fcf0f1;color:#7f1d1d;border:1px solid #f0a9ae;}
.octavawms-status-pill--info{background:#f0f6fc;color:#1d3e6f;border:1px solid #8cc3f9;}
.octavawms-cod-pill--no{background:#f6f7f7;color:#646970;border:1px solid #c3c4c7;}
.octavawms-cod-pill--yes{background:#fcf9e8;color:#734200;border:1px solid #dba617;}
.octavawms-label-shipment{flex:1 1 220px;display:flex;flex-direction:column;align-items:flex-end;gap:4px;text-align:right;max-width:100%;min-width:min(100%,12rem);}
.octavawms-label-shipment__meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;line-height:1.45;text-align:right;max-width:100%;}
.octavawms-label-shipment__str{display:inline-block;max-width:100%;}
.octavawms-label-shipment__cod{display:block;max-width:100%;}
.octavawms-toolbar-inline{margin-left:auto;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end;}
.octavawms-label-boxes-wrap{margin:0;padding:0;width:100%;}
.octavawms-label-top-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;align-items:center;gap:8px;margin:0 0 12px;width:100%;}
.octavawms-connect-section--label-boxes .octavawms-actions-row--label-secondary{margin-top:14px;padding-top:0;border-top:none;}
.octavawms-connect-section--label-boxes .octavawms-actions-row:not(.octavawms-actions-row--label-secondary){margin-top:16px;padding-top:4px;border-top:1px solid #dcdcde;}
.octavawms-sp-card .widefat{margin-bottom:0;}
.octavawms-sp-field{margin:0 0 14px;}
.octavawms-sp-field--sp{margin-bottom:16px;}
.octavawms-sp-context__label{display:block;font-size:13px;font-weight:600;margin:0 0 6px;}
.octavawms-sp-label-row{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px 12px;margin:0 0 6px;}
.octavawms-sp-label-row .octavawms-sp-context__label{margin:0;}
.octavawms-sp-toggle{font-size:13px;font-weight:400;}
.octavawms-sp-toggle label{display:inline-flex;align-items:center;gap:6px;margin:0;cursor:pointer;}
.octavawms-sp-gate-hint{margin:0 0 6px;font-size:12px;line-height:1.4;min-height:1.2em;}
.octavawms-sp-current-line{margin:0 0 12px;}
.select2-container.octavawms-select2-wrap .select2-selection--single{height:38px;border-radius:0;}
.select2-container.octavawms-select2-wrap .select2-selection__rendered{line-height:36px;padding-left:10px;}
.select2-container.octavawms-select2-wrap .select2-selection__arrow{height:36px;}
@keyframes octava-spin{to{transform:rotate(360deg);}}
.octavawms-muted{color:#646970;margin:0 0 8px;font-size:13px;line-height:1.5;}
.octavawms-text-danger{color:#b32d2e;}
.octavawms-places-summary{margin:0;padding:0 0 8px;font-size:13px;line-height:1.45;color:#50575e;font-weight:600;}
.octavawms-place-mult-wrap{display:inline-flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:4px;width:100%;}
abbr.octavawms-place-mult{margin:0;border:0;padding:0;font-size:11px;line-height:1.2;font-weight:600;color:#787c82;letter-spacing:.02em;cursor:help;text-decoration:none;}
.octavawms-place-td-weight{text-align:right;}
@keyframes octava-place-row-flash{0%,100%{box-shadow:inset 0 0 0 0 transparent}35%{box-shadow:inset 0 0 0 2px #2271b1}}
.octavawms-place-row--flash td{animation:octava-place-row-flash .9s ease-in-out;}
.octavawms-place-table-wrap{margin-top:14px;width:100%;overflow-x:auto;}
.octavawms-place-table.octavawms-place-table--compact{margin:0;width:100%;border-collapse:collapse;}
.octavawms-place-table.octavawms-place-table--compact thead th{background:#eef0f1;padding:10px 8px;margin:0;border:1px solid #c3c4c7;font-weight:600;font-size:13px;text-align:right;}
.octavawms-place-table.octavawms-place-table--compact thead th.octavawms-place-th-box{width:3.25em;}
.octavawms-place-table.octavawms-place-table--compact thead th.octavawms-place-th-actions{width:3.5em;text-align:center;}
.octavawms-place-table.octavawms-place-table--compact thead th.octavawms-place-th-dims{text-align:center;font-weight:600;}
.octavawms-place-table.octavawms-place-table--compact tbody td{border:1px solid #ddd;padding:8px 6px;vertical-align:middle;text-align:right;}
.octavawms-place-table.octavawms-place-table--compact tbody td.octavawms-place-td-actions{text-align:center;vertical-align:middle;}
.octavawms-place-table.octavawms-place-table--compact tbody td.octavawms-place-td-num{font-weight:600;}
.octavawms-place-remove.button-link,.octavawms-place-remove.button-link-delete{min-width:2em;text-align:center;font-size:18px;line-height:1;padding:0 4px;}
.octavawms-place-input{width:100%;max-width:4.75em;margin:0;box-sizing:border-box;height:32px;line-height:normal;padding:0 6px;border:1px solid #8c8f94;border-radius:0;font-size:13px;text-align:right;}
.octavawms-place-input:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;}
.octavawms-muted--tight{margin:0;}
.octavawms-spinner{display:inline-block;width:16px;height:16px;border:2px solid #c3c4c7;border-top-color:#2271b1;border-radius:50%;animation:octava-spin 0.7s linear infinite;vertical-align:middle;margin-right:6px;}
.octavawms-tracking-lock-banner{margin:0 0 14px;padding:12px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7;box-sizing:border-box;}
.octavawms-tracking-line{margin:0 0 8px;font-size:13px;line-height:1.45;}
.octavawms-shipment-locked-msg{margin:0 0 10px;}
.octavawms-label-viewer-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 12px;margin:0 0 12px;width:100%;box-sizing:border-box;}
.octavawms-label-viewer-title{flex:1 1 auto;min-width:0;margin:0;padding:0;border:0;background:transparent;font-size:14px;line-height:1.35;font-weight:600;}
.octavawms-label-viewer-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;align-items:center;flex:0 0 auto;margin-left:auto;}
.octavawms-label-viewer-frame-wrap{width:100%;max-width:500px;margin:0;}
.octavawms-label-viewer-iframe{width:100%;height:500px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;background:#fff;}
.octavawms-label-viewer-loading{margin:8px 0;}
.octavawms-label-viewer-error{margin:8px 0;}
.octavawms-label-viewer-fallback-dl{display:inline-block;margin-top:8px;}
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

        $orderId = $this->resolveOrderIdForScreen();
        if ($orderId <= 0) {
            return;
        }

        $pluginMain = dirname(__DIR__, 2) . '/octavawms-woocommerce.php';

        $scriptDeps = ['jquery'];
        if (wp_script_is('selectWoo', 'registered')) {
            wp_enqueue_script('selectWoo');
            $scriptDeps[] = 'selectWoo';
            if (wp_style_is('woocommerce_admin_styles', 'registered')) {
                wp_enqueue_style('woocommerce_admin_styles');
            }
        }

        wp_enqueue_script(
            'octavawms-order-panel',
            plugins_url('assets/js/admin-order-panel.js', $pluginMain),
            $scriptDeps,
            '1.9.9',
            true
        );

        $orderEditUrl = '';
        $wcOrder = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if ($wcOrder instanceof WC_Order && function_exists('wc_get_order_edit_url')) {
            $orderEditUrl = (string) wc_get_order_edit_url($wcOrder);
        }

        $weightUnitSlug = (string) apply_filters(
            'octavawms_places_summary_weight_unit',
            function_exists('get_option') ? (string) get_option('woocommerce_weight_unit', 'kg') : 'kg'
        );

        wp_localize_script('octavawms-order-panel', 'octavawmsOrderPanel', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'orderId' => $orderId,
            'orderEditUrl' => $orderEditUrl,
            'patchKindRetryPendingError' => LabelAjax::PATCH_KIND_RETRY_PENDING_ERROR,
            'patchKindRequeueEndingQueued' => LabelAjax::PATCH_KIND_REQUEUE_ENDING_QUEUED,
            'panelLoginNonce' => wp_create_nonce(ConnectService::PANEL_LOGIN_NONCE_ACTION),
            'statusNonce' => wp_create_nonce('octavawms_order_status_' . (string) $orderId),
            'uploadNonce' => wp_create_nonce('octavawms_upload_order_' . (string) $orderId),
            'generateLabelNonce' => wp_create_nonce('octavawms_generate_label_' . (string) $orderId),
            'fetchLabelNonce' => wp_create_nonce('octavawms_fetch_label_' . (string) $orderId),
            'cancelLabelNonce' => wp_create_nonce('octavawms_cancel_label_' . (string) $orderId),
            'connectorNonce' => wp_create_nonce('octavawms_connector_' . (string) $orderId),
            'deliveryStrategyOptions' => LabelAjax::deliveryStrategyOptionsForScript(),
            'weightUnit' => $weightUnitSlug,
            'weightUnitLabel' => $this->weightUnitLabelTranslated($weightUnitSlug),
            'strings' => [
                'loading' => __('Loading…', 'octavawms'),
                'error' => __('Could not load OctavaWMS status.', 'octavawms'),
                'noOrder' => __('This order is not in OctavaWMS yet. Upload it to create shipments and labels.', 'octavawms'),
                'uploadOrder' => __('Upload order', 'octavawms'),
                'uploading' => __('Uploading…', 'octavawms'),
                'orderSynced' => __('Order synced', 'octavawms'),
                'awaitingShipment' => __('Order is in OctavaWMS; waiting for a shipment (delivery request) to appear.', 'octavawms'),
                'shipment' => UiBranding::shipmentHeadingWord(),
                'labelReady' => __('Label Ready', 'octavawms'),
                'downloadLabel' => __('Download Label', 'octavawms'),
                'printLabel' => __('Print Label', 'octavawms'),
                'labelViewerTitle' => __('Shipping Label', 'octavawms'),
                'fetchLabel' => __('Load Label', 'octavawms'),
                'fetchingLabel' => __('Loading label…', 'octavawms'),
                'labelFetchError' => __('Could not load label. Try generating it first.', 'octavawms'),
                'labelPreviewError' => __('Unable to render label preview.', 'octavawms'),
                'trackingNumber' => __('Tracking', 'octavawms'),
                'cancelShipment' => __('Cancel Shipment', 'octavawms'),
                'cancellingShipment' => __('Cancelling…', 'octavawms'),
                'shipmentLocked' => __('Shipment is locked (tracking number assigned).', 'octavawms'),
                'generateLabel' => __('Generate Label', 'octavawms'),
                'regenerateLabel' => __('Re-generate Label', 'octavawms'),
                'generatingLabel' => __('Generating label…', 'octavawms'),
                'tryAgain' => __('Try again', 'octavawms'),
                'requeueEndingQueued' => __('Re-queue shipment', 'octavawms'),
                'requeueingEndingQueued' => __('Re-queuing…', 'octavawms'),
                'refreshStatus' => __('Refresh status', 'octavawms'),
                'loginToPanel' => __('Login to the panel', 'octavawms'),
                'panelLoginError' => __('Could not open Octava panel. Try connecting again or check logs.', 'octavawms'),
                'servicePointSection' => __('Edit shipment', 'octavawms'),
                'noShipmentForSection' => __('Available after a shipment exists for this order.', 'octavawms'),
                'searchPlaceholder' => __('Search pickup point…', 'octavawms'),
                'noLockers' => __('No lockers', 'octavawms'),
                'select' => __('Select', 'octavawms'),
                'applyServicePoint' => __('Apply service point', 'octavawms'),
                'servicePointFieldLabel' => __('Service point', 'octavawms'),
                'chooseServicePoint' => __('— Choose —', 'octavawms'),
                'servicePointDetails' => __('Details', 'octavawms'),
                'noDetailsYet' => __('Choose a pickup point from the list.', 'octavawms'),
                'spPreviewId' => __('ID', 'octavawms'),
                'spPreviewType' => __('Type', 'octavawms'),
                'spPreviewState' => __('State', 'octavawms'),
                'spPreviewAddress' => __('Address', 'octavawms'),
                'spPreviewPhone' => __('Phone', 'octavawms'),
                'spPreviewHours' => __('Working hours', 'octavawms'),
                'spPreviewTimetable' => __('Schedule', 'octavawms'),
                'spPreviewAiNote' => __('Routing note', 'octavawms'),
                'spOpenInMaps' => __('Open in Maps', 'octavawms'),
                'spDistanceMeters' => __('Distance: %s m', 'octavawms'),
                'saving' => __('Saving…', 'octavawms'),
                'noServicePoints' => __('No pickup points found.', 'octavawms'),
                'currentPoint' => __('Current', 'octavawms'),
                'noPlaces' => __('No boxes/places yet.', 'octavawms'),
                'addPlace' => __('Add box', 'octavawms'),
                'save' => __('Save', 'octavawms'),
                'removePlace' => __('Remove box', 'octavawms'),
                'boxColumn' => __('Box', 'octavawms'),
                'placeActionsColumn' => __('Actions', 'octavawms'),
                'weightG' => __('Weight (g)', 'octavawms'),
                'placeTableWeightHeader' => __('(g)', 'octavawms'),
                'placeTableDimsHeader' => __('(W, H, L) mm', 'octavawms'),
                'placeTableDimsHeaderTitle' => __('Width, height, length in millimetres', 'octavawms'),
                'strategyForAi' => __('Strategy for AI', 'octavawms'),
                'deliveryCarrier' => __('Delivery carrier', 'octavawms'),
                'recipientLocality' => __('Recipient locality', 'octavawms'),
                'carrierPlaceholder' => __('Search and select carrier…', 'octavawms'),
                'localityPlaceholder' => __('Search city (e.g. Varna)…', 'octavawms'),
                'pickupPointPlaceholder' => __('Search pickup point…', 'octavawms'),
                'selectCarrierLocalityFirst' => __('Select carrier and locality first.', 'octavawms'),
                'shipmentPendingErrorGeneric' => __('OctavaWMS could not process this shipment. See the message below or open the delivery request in OctavaWMS.', 'octavawms'),
                'retryPendingError' => __('Retry', 'octavawms'),
                'retryingPendingError' => __('Retrying…', 'octavawms'),
                'shipmentQueuedInfo' => __('This shipment is queued for AI processing. Wait until it finishes before changing settings, or continue if your workflow allows it.', 'octavawms'),
                'localitySearchMin' => __('Type at least 2 characters to search.', 'octavawms'),
                'needSelectWoo' => __('Shipment fields require WooCommerce admin (SelectWoo). Ensure WooCommerce is active.', 'octavawms'),
                'labelPanelSrHeading' => __('Shipping labels and parcel boxes', 'octavawms'),
                'widthMm' => __('W', 'octavawms'),
                'heightMm' => __('H', 'octavawms'),
                'lengthMm' => __('L', 'octavawms'),
                'editOrder' => __('Edit order', 'octavawms'),
                'shipmentLabel' => UiBranding::shipmentHeadingWord(),
                'shipmentStatus' => __('Status', 'octavawms'),
                'codNo' => __('No COD', 'octavawms'),
                'codYes' => __('Cash on delivery', 'octavawms'),
                'placesTotalOneBox' => __('1 box total', 'octavawms'),
                'placesTotalBoxes' => __('%d boxes total', 'octavawms'),
                'placeRemoveBlockedTitle' => __('This box holds items and cannot be removed.', 'octavawms'),
                'placesSummaryGramsLine' => __('%1$s · %2$d g', 'octavawms'),
                'generateLabelNeedBoxes' => __('Add at least one box before generating a label.', 'octavawms'),
            ],
        ]);
    }

    private function weightUnitLabelTranslated(string $slug): string
    {
        switch ($slug) {
            case 'g':
                return function_exists('__') ? (string) __('g', 'woocommerce') : $slug;
            case 'kg':
                return function_exists('__') ? (string) __('kg', 'woocommerce') : $slug;
            case 'lbs':
                return function_exists('__') ? (string) __('lbs', 'woocommerce') : $slug;
            case 'oz':
                return function_exists('__') ? (string) __('oz', 'woocommerce') : $slug;
            default:
                return $slug;
        }
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
