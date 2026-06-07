(function (window) {
    'use strict';

    function text(value) {
        return value == null ? '' : String(value).trim();
    }

    function compact(parts, separator) {
        return (parts || [])
            .map(text)
            .filter(Boolean)
            .filter(function (part, index, all) {
                return all.indexOf(part) === index;
            })
            .join(separator);
    }

    function contains(haystack, needle) {
        haystack = text(haystack).toLowerCase();
        needle = text(needle).toLowerCase();

        return haystack !== '' && needle !== '' && haystack.indexOf(needle) !== -1;
    }

    function formatPickupPoint(item, formatDistance) {
        item = item || {};
        var title = text(item.name) || text(item.title) || text(item.id) || 'Pickup point';
        var address = compact([item.address, item.address2], ' ');
        var city = contains(address, item.city) ? '' : item.city;
        var postcode = contains(address, item.postcode) || (address && !city) ? '' : item.postcode;
        var locality = compact([city, postcode], ', ');
        if (locality) {
            address = compact([address, locality], ' ');
        }
        var meta = [];
        if (item.distanceKm !== null && item.distanceKm !== undefined && typeof formatDistance === 'function') {
            meta.push(formatDistance(item.distanceKm));
        }

        return {
            title: title,
            address: address,
            meta: compact(meta, ' · ')
        };
    }

    window.octavawmsCheckoutDeliveryHelpers = {
        formatPickupPoint: formatPickupPoint
    };
})(window);
