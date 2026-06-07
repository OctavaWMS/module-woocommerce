(function ($) {
    'use strict';

    var cfg = window.octavawmsCheckoutDelivery || {};
    var prefix = cfg.methodPrefix || 'delivery_with_orderadmin';
    var strings = cfg.strings || {};
    var pending = null;
    var searchTimer = null;
    var checkoutLoading = false;

    function selectedRateId() {
        var $checked = $('input[name^="shipping_method"]:checked');
        if ($checked.length) {
            return String($checked.val() || '');
        }
        var $single = $('input[name^="shipping_method"]');
        return String($single.first().val() || '');
    }

    function isOctavaRate(rateId) {
        return rateId === prefix || rateId.indexOf(prefix + ':') === 0;
    }

    function ensurePanel() {
        var $panel = $('#octavawms-checkout-delivery');
        if ($panel.length) {
            return $panel;
        }

        $panel = $('<div/>', {
            id: 'octavawms-checkout-delivery',
            class: 'octavawms-checkout-delivery'
        });
        var $shipping = $('#shipping_method').first();
        if ($shipping.length) {
            $shipping.after($panel);
        } else {
            $('.woocommerce-shipping-methods').first().after($panel);
        }

        return $panel;
    }

    function ensureLoadingNotice() {
        var $notice = $('#octavawms-shipping-loading-notice');
        if ($notice.length) {
            return $notice;
        }

        $notice = $('<div/>', {
            id: 'octavawms-shipping-loading-notice',
            class: 'octavawms-shipping-loading-notice',
            role: 'status',
            'aria-live': 'polite'
        }).text(strings.loadingShipping || 'Loading shipping options...');

        var $shipping = $('#shipping_method').first();
        if ($shipping.length) {
            $shipping.before($notice);
        } else {
            $('.woocommerce-shipping-methods').first().before($notice);
        }

        return $notice;
    }

    function setHidden(name, value) {
        var id = 'octavawms_' + name;
        var $field = $('#' + id);
        if (!$field.length) {
            $field = $('<input/>', { type: 'hidden', id: id, name: name });
            $('form.checkout').append($field);
        }
        $field.val(value || '');
    }

    function clearSelection() {
        setHidden('octavawms_service_point_id', '');
        setHidden('octavawms_delivery_rate_id', selectedRateId());
    }

    function clearDeliverySelection() {
        setHidden('octavawms_service_point_id', '');
        setHidden('octavawms_delivery_rate_id', '');
    }

    function abortPickupRequest() {
        clearTimeout(searchTimer);
        if (pending && pending.abort) {
            pending.abort();
        }
        pending = null;
    }

    function setCheckoutLoading(isLoading) {
        checkoutLoading = isLoading;
        $('form.checkout').toggleClass('octavawms-shipping-loading', isLoading);
        $('#shipping_method, .woocommerce-shipping-methods, #octavawms-checkout-delivery')
            .attr('aria-busy', isLoading ? 'true' : 'false')
            .find('input, select, button')
            .prop('disabled', isLoading);
        $('#place_order')
            .prop('disabled', isLoading)
            .toggleClass('octavawms-disabled-by-shipping', isLoading);

        if (isLoading) {
            abortPickupRequest();
            clearDeliverySelection();
            ensureLoadingNotice().show();
            ensurePanel().empty().hide();
        } else {
            ensureLoadingNotice().hide();
        }
    }

    function renderPoints($panel, rateId, response, search) {
        var data = response && response.data ? response.data : {};
        if (!data.requiresPoint) {
            $panel.html('<div class="octavawms-delivery-summary">' + escapeHtml(data.title || '') + '</div>');
            setHidden('octavawms_delivery_rate_id', rateId);
            setHidden('octavawms_service_point_id', '');
            return;
        }

        var items = data.items || [];
        var html = '<div class="octavawms-delivery-title">' + escapeHtml(strings.pickupTitle || 'Pickup point') + '</div>';
        html += '<input type="search" class="octavawms-point-search" value="' + escapeHtml(search || '') + '" placeholder="' + escapeHtml(strings.searchPickup || 'Search pickup point') + '" autocomplete="off">';
        html += '<div class="octavawms-point-list">';
        if (!items.length) {
            html += '<div class="octavawms-point-empty">' + escapeHtml(strings.noPoints || 'No pickup points were found.') + '</div>';
        }
        items.forEach(function (item) {
            html += '<button type="button" class="octavawms-point" data-id="' + escapeHtml(String(item.id || '')) + '">';
            html += '<span class="octavawms-point-name">' + escapeHtml(item.name || '') + '</span>';
            if (item.address) {
                html += '<span class="octavawms-point-address">' + escapeHtml(item.address) + '</span>';
            }
            html += '</button>';
        });
        html += '</div>';
        $panel.html(html);
        setHidden('octavawms_delivery_rate_id', rateId);
    }

    function loadPoints(rateId, search) {
        if (checkoutLoading) {
            return;
        }
        var $panel = ensurePanel();
        $panel.show().html('<div class="octavawms-point-loading">' + escapeHtml(strings.loading || 'Loading...') + '</div>');
        abortPickupRequest();
        pending = $.post(cfg.ajaxUrl, {
            action: 'octavawms_checkout_service_points',
            nonce: cfg.nonce,
            rate_id: rateId,
            search: search || ''
        }).done(function (response) {
            renderPoints($panel, rateId, response, search || '');
        }).fail(function () {
            $panel.html('<div class="octavawms-point-empty">' + escapeHtml(strings.noPoints || 'No pickup points were found.') + '</div>');
        });
    }

    function refreshPanel() {
        if (checkoutLoading) {
            return;
        }
        var rateId = selectedRateId();
        var $panel = ensurePanel();
        clearSelection();

        if (!isOctavaRate(rateId)) {
            $panel.empty().hide();
            return;
        }

        loadPoints(rateId, '');
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[ch];
        });
    }

    $(document.body)
        .on('update_checkout', function () {
            setCheckoutLoading(true);
        })
        .on('updated_checkout checkout_error', function () {
            setCheckoutLoading(false);
            refreshPanel();
        })
        .on('checkout_place_order', function () {
            return checkoutLoading ? false : undefined;
        })
        .on('change', 'input[name^="shipping_method"]', refreshPanel)
        .on('input', '.octavawms-point-search', function () {
            if (checkoutLoading) {
                return;
            }
            var search = String($(this).val() || '');
            var rateId = selectedRateId();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                clearSelection();
                loadPoints(rateId, search);
            }, 250);
        })
        .on('click', '.octavawms-point', function () {
            if (checkoutLoading) {
                return;
            }
            $('.octavawms-point').removeClass('is-selected').find('.octavawms-point-selected').remove();
            var $point = $(this).addClass('is-selected');
            $point.append('<span class="octavawms-point-selected">' + escapeHtml(strings.selected || 'Selected') + '</span>');
            setHidden('octavawms_service_point_id', $point.data('id'));
            setHidden('octavawms_delivery_rate_id', selectedRateId());
        });

    $(refreshPanel);
})(jQuery);
