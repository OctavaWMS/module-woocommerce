(function (window) {
    'use strict';

    var cfg = window.octavawmsCheckoutDeliveryBlocks || {};
    var strings = cfg.strings || {};
    var element = window.wp && window.wp.element;
    var plugins = window.wp && window.wp.plugins;
    var data = window.wp && window.wp.data;
    var blocks = window.wc && window.wc.blocksCheckout;
    if (!element || !plugins || !data || !blocks || !blocks.ExperimentalOrderShippingPackages) {
        return;
    }

    var createElement = element.createElement;
    var useEffect = element.useEffect;
    var useState = element.useState;
    var methodPrefix = cfg.methodPrefix || 'delivery_with_orderadmin';

    function rateIdFromCart(cart) {
        var found = '';
        function visit(value) {
            if (found || !value || typeof value !== 'object') {
                return;
            }
            if (!Array.isArray(value)) {
                var id = value.rateId || value.rate_id || value.rateKey || value.rate_key || '';
                var selected = value.selected === true || value.isSelected === true || value.is_selected === true;
                if (selected && isOctavaRate(String(id))) {
                    found = String(id);
                    return;
                }
            }
            Object.keys(value).forEach(function (key) {
                visit(value[key]);
            });
        }
        visit(cart && (cart.shippingRates || cart.shipping_rates || cart));
        return found;
    }

    function isOctavaRate(rateId) {
        return rateId === methodPrefix || rateId.indexOf(methodPrefix + ':') === 0;
    }

    function pointLabel(point) {
        var title = String(point.name || point.title || point.id || '');
        var address = [point.address, point.city, point.postcode].filter(Boolean).join(', ');
        return address ? title + ' — ' + address : title;
    }

    function PickupPointSelector() {
        var cart = data.useSelect(function (select) {
            var store = select('wc/store/cart');
            return store && store.getCartData ? store.getCartData() : {};
        }, []);
        var rateId = rateIdFromCart(cart);
        var state = useState({loading: false, requiresPoint: false, points: [], selected: '', error: ''});
        var selection = state[0];
        var setSelection = state[1];

        useEffect(function () {
            if (!rateId) {
                setSelection({loading: false, requiresPoint: false, points: [], selected: '', error: ''});
                return;
            }
            var body = new URLSearchParams({
                action: 'octavawms_checkout_service_points',
                nonce: String(cfg.nonce || ''),
                rate_id: rateId,
                search: ''
            });
            setSelection({loading: true, requiresPoint: false, points: [], selected: '', error: ''});
            window.fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (response) {
                var payload = response && response.success ? response.data : null;
                if (!payload) {
                    throw new Error('service-points');
                }
                setSelection({
                    loading: false,
                    requiresPoint: Boolean(payload.requiresPoint),
                    points: Array.isArray(payload.items) ? payload.items : [],
                    selected: '',
                    error: ''
                });
                return blocks.extensionCartUpdate({
                    namespace: cfg.updateNamespace,
                    data: {rateId: rateId, servicePointId: 0}
                });
            }).catch(function () {
                setSelection({loading: false, requiresPoint: true, points: [], selected: '', error: strings.error || 'Could not load pickup points.'});
            });
        }, [rateId]);

        function updatePoint(event) {
            var pointId = String(event.target.value || '');
            setSelection(Object.assign({}, selection, {selected: pointId, error: strings.saving || 'Saving pickup point...'}));
            blocks.extensionCartUpdate({
                namespace: cfg.updateNamespace,
                data: {rateId: rateId, servicePointId: Number(pointId)}
            }).then(function () {
                setSelection(function (current) {
                    return Object.assign({}, current, {error: ''});
                });
            }).catch(function () {
                setSelection(function (current) {
                    return Object.assign({}, current, {selected: '', error: strings.error || 'Could not update the pickup point.'});
                });
            });
        }

        if (!rateId || (!selection.loading && !selection.requiresPoint)) {
            return null;
        }
        if (selection.loading) {
            return createElement('div', {className: 'octavawms-blocks-pickup-point', 'aria-live': 'polite'}, strings.loading || 'Loading pickup points...');
        }
        return createElement('div', {className: 'octavawms-blocks-pickup-point'},
            createElement('label', {htmlFor: 'octavawms-blocks-service-point'}, strings.pickupTitle || 'Pickup point'),
            createElement('select', {
                id: 'octavawms-blocks-service-point',
                value: selection.selected,
                onChange: updatePoint,
                required: true,
                disabled: !selection.points.length
            },
            createElement('option', {value: ''}, selection.points.length ? (strings.choosePickup || 'Choose pickup point') : (strings.noPoints || 'No pickup points were found.')),
            selection.points.map(function (point) {
                return createElement('option', {key: String(point.id), value: String(point.id)}, pointLabel(point));
            })),
            selection.error ? createElement('div', {role: 'status', 'aria-live': 'polite'}, selection.error) : null
        );
    }

    function render() {
        return createElement(blocks.ExperimentalOrderShippingPackages, null, createElement(PickupPointSelector));
    }

    plugins.registerPlugin('octavawms-checkout-delivery-blocks', {
        render: render,
        scope: 'woocommerce-checkout'
    });
}(window));
