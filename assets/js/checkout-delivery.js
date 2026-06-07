(function ($) {
    'use strict';

    var LEAFLET_CSS_URL = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    var LEAFLET_JS_URL = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    var OSM_TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    var OSM_TILE_ATTRIBUTION = '&copy; OpenStreetMap contributors';
    var IZPRATI_PLANE_PATH_LARGE = 'm59.41,75.81L364.61,0H59.41v75.81Z';
    var IZPRATI_PLANE_PATH_SMALL = 'm0,37.9L152.6,0H0v37.9Z';

    var cfg = window.octavawmsCheckoutDelivery || {};
    var prefix = cfg.methodPrefix || 'delivery_with_orderadmin';
    var strings = cfg.strings || {};
    var helpers = window.octavawmsCheckoutDeliveryHelpers || {};
    var pending = null;
    var searchTimer = null;
    var checkoutLoading = false;
    var leafletLoadPromise = null;
    var pickupMap = null;
    var currentState = {
        rateId: '',
        search: '',
        items: [],
        origin: null,
        mapVisible: false,
        message: '',
        loading: false
    };

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

    function getHidden(name) {
        return String($('#octavawms_' + name).val() || '');
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

    function resetMap() {
        if (pickupMap && pickupMap.remove) {
            pickupMap.remove();
        }
        pickupMap = null;
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
            resetMap();
            ensureLoadingNotice().show();
            ensurePanel().empty().hide();
        } else {
            ensureLoadingNotice().hide();
        }
    }

    function ensureIzpratiAttribution() {
        if (!cfg.showIzpratiAttribution) {
            return;
        }
        var $heading = findShippingHeading();
        if (!$heading.length) {
            return;
        }

        var $row = $heading.parent('.octavawms-shipping-heading-row');
        if (!$row.length) {
            $heading.wrap('<div class="octavawms-shipping-heading-row"></div>');
            $row = $heading.parent('.octavawms-shipping-heading-row');
        }
        $heading.addClass('octavawms-shipping-heading-title');
        if (!$row.find('.octavawms-izprati-attribution').length) {
            $row.append(createIzpratiAttributionHtml());
        }
    }

    function findShippingHeading() {
        var $shipping = $('#shipping_method').first();
        if (!$shipping.length) {
            return $();
        }

        var shippingTop = $shipping.offset() ? $shipping.offset().top : 0;
        var targetText = String(strings.shippingTitle || 'Shipping').trim().toLowerCase();
        var $best = $();
        var bestDistance = Number.POSITIVE_INFINITY;
        $('h2, h3, .woocommerce-shipping-title').each(function () {
            var $candidate = $(this);
            var text = String($candidate.text() || '').trim().toLowerCase();
            if (text !== targetText) {
                return;
            }
            var offset = $candidate.offset();
            if (!offset || offset.top > shippingTop + 8) {
                return;
            }
            var distance = shippingTop - offset.top;
            if (distance < bestDistance) {
                bestDistance = distance;
                $best = $candidate;
            }
        });

        return $best;
    }

    function createIzpratiAttributionHtml() {
        var prefix = String(strings.poweredByPrefix || 'Работи с ');
        var mark = String(strings.poweredByMarkWord || 'ИЗПРАТИ.БГ');
        var url = String(strings.attributionUrl || 'https://izprati.bg');
        return '<a class="octavawms-izprati-attribution" href="' + escapeAttribute(url) + '" target="_blank" rel="noopener noreferrer">' +
            '<span class="octavawms-powered-by-prefix">' + escapeHtml(prefix) + '</span>' +
            '<span class="octavawms-izprati-word">' + escapeHtml(mark) + '</span>' +
            '<svg class="octavawms-izprati-plane" viewBox="0 0 68 16" width="68" height="16" aria-hidden="true" focusable="false">' +
            '<svg x="0" y="1" width="68" height="14" viewBox="0 0 364.61 75.81" preserveAspectRatio="xMidYMid meet">' +
            '<path d="' + IZPRATI_PLANE_PATH_LARGE + '" fill="#d42d27"></path>' +
            '<path d="' + IZPRATI_PLANE_PATH_SMALL + '" fill="#ab2924"></path>' +
            '</svg></svg></a>';
    }

    function renderPoints($panel, rateId, response, search) {
        var data = response && response.data ? response.data : {};
        if (!data.requiresPoint) {
            resetMap();
            currentState.rateId = rateId;
            currentState.search = '';
            currentState.items = [];
            currentState.message = '';
            currentState.loading = false;
            $panel.removeClass('octavawms-checkout-delivery--pickup');
            $panel.html('<div class="octavawms-delivery-summary">' + escapeHtml(data.title || '') + '</div>');
            setHidden('octavawms_delivery_rate_id', rateId);
            setHidden('octavawms_service_point_id', '');
            return;
        }

        currentState.rateId = rateId;
        currentState.search = search || '';
        currentState.items = normalizeItems(data.items || []);
        currentState.loading = false;
        $panel.addClass('octavawms-checkout-delivery--pickup');
        if ($panel.find('.octavawms-point-controls').length) {
            replacePickupList($panel);
            if (currentState.mapVisible) {
                renderMap($panel);
            } else {
                resetMap();
            }
        } else {
            renderPickupPanel($panel);
        }
        setHidden('octavawms_delivery_rate_id', rateId);
    }

    function renderPickupPanel($panel) {
        var items = currentState.items.slice();
        var html = '<h3 class="octavawms-delivery-title">' + escapeHtml(strings.pickupTitle || 'Pickup point') + '</h3>';
        html += '<div class="octavawms-point-controls">';
        html += '<input type="search" class="octavawms-point-search" value="' + escapeHtml(currentState.search || '') + '" placeholder="' + escapeHtml(strings.searchPickup || 'Search pickup point') + '" autocomplete="off">';
        html += '<div class="octavawms-point-actions">';
        html += '<button type="button" class="octavawms-near-me">' + escapeHtml(strings.nearMe || 'Near me') + '</button>';
        html += '<button type="button" class="octavawms-map-toggle" aria-pressed="' + (currentState.mapVisible ? 'true' : 'false') + '">' + escapeHtml(currentState.mapVisible ? (strings.list || 'List') : (strings.map || 'Map')) + '</button>';
        html += '</div>';
        html += '</div>';
        if (currentState.message) {
            html += '<div class="octavawms-point-message">' + escapeHtml(currentState.message) + '</div>';
        }
        html += '<div class="octavawms-map-wrap' + (currentState.mapVisible ? ' is-visible' : '') + '">';
        html += '<div class="octavawms-map-canvas" aria-label="' + escapeHtml(strings.map || 'Map') + '"></div>';
        html += '</div>';
        html += pickupListHtml(items);
        $panel.html(html);

        if (currentState.mapVisible) {
            renderMap($panel);
        } else {
            resetMap();
        }
    }

    function pickupListHtml(items) {
        var html = '<div class="octavawms-point-list' + (currentState.loading ? ' is-loading' : '') + '" aria-busy="' + (currentState.loading ? 'true' : 'false') + '">';
        if (currentState.loading) {
            html += '<div class="octavawms-point-loading">' + escapeHtml(strings.loading || 'Loading...') + '</div>';
        } else if (!items.length) {
            html += '<div class="octavawms-point-empty">' + escapeHtml(strings.noPoints || 'No pickup points were found.') + '</div>';
        }
        if (!currentState.loading) {
            items.forEach(function (item) {
                var selected = String(item.id || '') === getHidden('octavawms_service_point_id');
                var display = formatPickupPoint(item);
                html += '<button type="button" class="octavawms-point' + (selected ? ' is-selected' : '') + '" data-id="' + escapeHtml(String(item.id || '')) + '">';
                html += '<span class="octavawms-point-main">';
                html += '<span class="octavawms-point-top"><span class="octavawms-point-name">' + escapeHtml(display.title) + '</span></span>';
                if (display.address) {
                    html += '<span class="octavawms-point-address">' + escapeHtml(display.address) + '</span>';
                }
                if (display.meta) {
                    html += '<span class="octavawms-point-meta">' + escapeHtml(display.meta) + '</span>';
                }
                if (selected) {
                    html += '<span class="octavawms-point-selected">' + escapeHtml(strings.selected || 'Selected') + '</span>';
                }
                html += '</span>';
                html += '</button>';
            });
        }
        html += '</div>';

        return html;
    }

    function replacePickupList($panel) {
        var $list = $panel.find('.octavawms-point-list').first();
        if (!$list.length) {
            renderPickupPanel($panel);
            return;
        }

        $list.replaceWith(pickupListHtml(currentState.items.slice()));
    }

    function loadPoints(rateId, search, origin) {
        if (checkoutLoading) {
            return;
        }
        var $panel = ensurePanel();
        abortPickupRequest();
        currentState.rateId = rateId;
        currentState.search = search || '';
        currentState.loading = true;

        if ($panel.hasClass('octavawms-checkout-delivery--pickup')) {
            $panel.show();
            replacePickupList($panel);
        } else {
            $panel.show().html('<div class="octavawms-point-loading">' + escapeHtml(strings.loading || 'Loading...') + '</div>');
        }

        var payload = {
            action: 'octavawms_checkout_service_points',
            nonce: cfg.nonce,
            rate_id: rateId,
            search: search || ''
        };
        if (origin && isFiniteNumber(origin.lat) && isFiniteNumber(origin.lng)) {
            payload.lat = Number(origin.lat).toFixed(5);
            payload.lng = Number(origin.lng).toFixed(5);
        }

        pending = $.post(cfg.ajaxUrl, payload).done(function (response) {
            renderPoints($panel, rateId, response, search || '');
        }).fail(function (xhr, textStatus) {
            if (textStatus === 'abort') {
                return;
            }
            resetMap();
            currentState.loading = false;
            currentState.items = [];
            if ($panel.hasClass('octavawms-checkout-delivery--pickup')) {
                replacePickupList($panel);
            } else {
                $panel.html('<div class="octavawms-point-empty">' + escapeHtml(strings.noPoints || 'No pickup points were found.') + '</div>');
            }
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
            resetMap();
            currentState.rateId = '';
            currentState.items = [];
            currentState.loading = false;
            $panel.removeClass('octavawms-checkout-delivery--pickup').empty().hide();
            return;
        }

        loadPoints(rateId, '', currentState.origin);
    }

    function normalizeItems(items) {
        return (items || []).map(function (item) {
            var normalized = {
                id: item.id,
                name: item.name || '',
                address: item.address || '',
                address2: item.address2 || '',
                city: item.city || '',
                postcode: item.postcode || '',
                type: item.type || '',
                typeLabel: item.typeLabel || '',
                lat: toNumber(item.lat),
                lng: toNumber(item.lng),
                distanceKm: null
            };
            if (currentState.origin && isFiniteNumber(normalized.lat) && isFiniteNumber(normalized.lng)) {
                normalized.distanceKm = distanceKm(currentState.origin.lat, currentState.origin.lng, normalized.lat, normalized.lng);
            }
            return normalized;
        }).sort(function (a, b) {
            if (a.distanceKm === null && b.distanceKm === null) {
                return 0;
            }
            if (a.distanceKm === null) {
                return 1;
            }
            if (b.distanceKm === null) {
                return -1;
            }
            return a.distanceKm - b.distanceKm;
        });
    }

    function requestNearMe($button) {
        if (checkoutLoading) {
            return;
        }
        if (!navigator.geolocation || !navigator.geolocation.getCurrentPosition) {
            currentState.message = strings.locationUnavailable || 'Location is not available in this browser.';
            renderPickupPanel(ensurePanel());
            return;
        }

        var original = $button.text();
        $button.prop('disabled', true).addClass('is-loading').text(strings.locating || 'Locating...');
        currentState.message = '';

        navigator.geolocation.getCurrentPosition(function (position) {
            var lat = Number(position && position.coords ? position.coords.latitude : NaN);
            var lng = Number(position && position.coords ? position.coords.longitude : NaN);
            $button.prop('disabled', false).removeClass('is-loading').text(original);
            if (!isFiniteNumber(lat) || !isFiniteNumber(lng)) {
                currentState.message = strings.locationDenied || 'Could not use your location. Showing pickup points for the selected city.';
                renderPickupPanel(ensurePanel());
                return;
            }
            currentState.origin = {
                lat: Number(lat.toFixed(5)),
                lng: Number(lng.toFixed(5))
            };
            clearSelection();
            loadPoints(currentState.rateId || selectedRateId(), currentState.search || '', currentState.origin);
        }, function () {
            $button.prop('disabled', false).removeClass('is-loading').text(original);
            currentState.message = strings.locationDenied || 'Could not use your location. Showing pickup points for the selected city.';
            currentState.items = normalizeItems(currentState.items);
            renderPickupPanel(ensurePanel());
        }, {
            enableHighAccuracy: false,
            timeout: 6000,
            maximumAge: 300000
        });
    }

    function selectPointById(pointId) {
        if (!pointId) {
            return;
        }
        $('.octavawms-point').removeClass('is-selected').find('.octavawms-point-selected').remove();
        var $point = $('.octavawms-point').filter(function () {
            return String($(this).data('id') || '') === String(pointId);
        }).first();
        if ($point.length) {
            $point.addClass('is-selected');
            ($point.find('.octavawms-point-main').first().length ? $point.find('.octavawms-point-main').first() : $point)
                .append('<span class="octavawms-point-selected">' + escapeHtml(strings.selected || 'Selected') + '</span>');
        }
        setHidden('octavawms_service_point_id', pointId);
        setHidden('octavawms_delivery_rate_id', selectedRateId());
    }

    function renderMap($panel) {
        var $canvas = $panel.find('.octavawms-map-canvas').first();
        if (!$canvas.length) {
            return;
        }
        resetMap();
        var points = currentState.items.filter(function (item) {
            return isFiniteNumber(item.lat) && isFiniteNumber(item.lng);
        });
        if (!points.length) {
            $canvas.html('<div class="octavawms-map-empty">' + escapeHtml(strings.noPoints || 'No pickup points were found.') + '</div>');
            return;
        }
        $canvas.html('<div class="octavawms-map-loading">' + escapeHtml(strings.mapLoading || strings.loading || 'Loading map...') + '</div>');

        ensureLeafletLoaded().then(function (L) {
            if (!currentState.mapVisible) {
                return;
            }
            var canvas = $canvas.get(0);
            if (!canvas) {
                return;
            }
            $canvas.empty();
            var first = currentState.origin || points[0];
            pickupMap = L.map(canvas).setView([first.lat, first.lng], 13);
            L.tileLayer(OSM_TILE_URL, {
                attribution: OSM_TILE_ATTRIBUTION,
                maxZoom: 19
            }).addTo(pickupMap);

            var bounds = [];
            if (currentState.origin) {
                L.circleMarker([currentState.origin.lat, currentState.origin.lng], {
                    radius: 7,
                    color: '#2563eb',
                    fillColor: '#2563eb',
                    fillOpacity: 0.9
                }).addTo(pickupMap);
                bounds.push([currentState.origin.lat, currentState.origin.lng]);
            }

            points.forEach(function (item) {
                bounds.push([item.lat, item.lng]);
                var marker = L.marker([item.lat, item.lng]).addTo(pickupMap);
                var display = formatPickupPoint(item);
                var popup = '<strong>' + escapeHtml(display.title || 'Pickup point') + '</strong>';
                if (display.address) {
                    popup += '<br>' + escapeHtml(display.address);
                }
                if (display.meta) {
                    popup += '<br>' + escapeHtml(display.meta);
                }
                marker.bindPopup(popup);
                marker.on('click', function () {
                    selectPointById(String(item.id || ''));
                    marker.openPopup();
                });
            });

            if (bounds.length > 1) {
                pickupMap.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
            }
            setTimeout(function () {
                if (pickupMap && pickupMap.invalidateSize) {
                    pickupMap.invalidateSize();
                }
            }, 50);
        }).catch(function () {
            $canvas.html('<div class="octavawms-map-empty">' + escapeHtml(strings.noPoints || 'No pickup points were found.') + '</div>');
        });
    }

    function ensureLeafletLoaded() {
        if (window.L) {
            return Promise.resolve(window.L);
        }
        if (!leafletLoadPromise) {
            ensureStylesheet(LEAFLET_CSS_URL, 'octavawms-leaflet-css');
            leafletLoadPromise = loadScriptOnce(LEAFLET_JS_URL, 'octavawms-leaflet-js').then(function () {
                if (!window.L) {
                    throw new Error('Leaflet did not initialize');
                }
                return window.L;
            });
        }
        return leafletLoadPromise;
    }

    function ensureStylesheet(url, id) {
        if (document.getElementById(id)) {
            return;
        }
        var link = document.createElement('link');
        link.id = id;
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
    }

    function loadScriptOnce(url, id) {
        return new Promise(function (resolve, reject) {
            var existing = document.getElementById(id);
            if (existing) {
                existing.addEventListener('load', resolve);
                existing.addEventListener('error', reject);
                return;
            }
            var script = document.createElement('script');
            script.id = id;
            script.src = url;
            script.async = true;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    function distanceKm(lat1, lng1, lat2, lng2) {
        var radius = 6371;
        var dLat = toRadians(lat2 - lat1);
        var dLng = toRadians(lng2 - lng1);
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return radius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function formatDistance(value) {
        var distance = Number(value);
        if (!isFiniteNumber(distance)) {
            return '';
        }
        return distance < 1 ? Math.round(distance * 1000) + ' m' : distance.toFixed(1) + ' km';
    }

    function formatPickupPoint(item) {
        if (helpers && typeof helpers.formatPickupPoint === 'function') {
            return helpers.formatPickupPoint(item, formatDistance);
        }

        return {
            title: item && item.name ? String(item.name) : 'Pickup point',
            address: item && item.address ? String(item.address) : '',
            meta: item && item.distanceKm !== null && item.distanceKm !== undefined ? formatDistance(item.distanceKm) : ''
        };
    }

    function toRadians(value) {
        return Number(value) * Math.PI / 180;
    }

    function toNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        var number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function isFiniteNumber(value) {
        return typeof value === 'number' && Number.isFinite(value);
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

    function escapeAttribute(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    $(document.body)
        .on('update_checkout', function () {
            setCheckoutLoading(true);
        })
        .on('updated_checkout checkout_error', function () {
            setCheckoutLoading(false);
            ensureIzpratiAttribution();
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
                currentState.search = search;
                clearSelection();
                loadPoints(rateId, search, currentState.origin);
            }, 250);
        })
        .on('click', '.octavawms-near-me', function () {
            requestNearMe($(this));
        })
        .on('click', '.octavawms-map-toggle', function () {
            if (checkoutLoading) {
                return;
            }
            currentState.mapVisible = !currentState.mapVisible;
            renderPickupPanel(ensurePanel());
        })
        .on('click', '.octavawms-point', function () {
            if (checkoutLoading) {
                return;
            }
            selectPointById(String($(this).data('id') || ''));
        });

    $(function () {
        ensureIzpratiAttribution();
        refreshPanel();
    });
})(jQuery);
