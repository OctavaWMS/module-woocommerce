# WooCommerce checkout delivery

This plugin provides a classic WooCommerce shipping method for OctavaWMS delivery calculation. The canonical Woo shipping method id is:

```text
delivery_with_orderadmin
```

Dynamic checkout rates use the same prefix:

```text
delivery_with_orderadmin:<deliveryServiceId>:<rateId|default>:<index>
```

The server-side WooCommerce integration must treat both the exact id and prefixed ids as OctavaWMS delivery rates.

## Calculator request

Checkout rate calculation posts to:

```text
POST /api/delivery-services/calculator
```

The request is intentionally compact. It uses the cart destination postcode/country, selected sender, cart weight, and cart value:

```json
{
  "sender": 19225,
  "to": {
    "postcode": "9002",
    "country": "BG"
  },
  "weight": 10,
  "estimatedCost": 2.43,
  "payment": 0,
  "timeout": 30
}
```

Notes:

- If Woo products have no weight, the plugin sends a 10 gram fallback.
- `servicePoints` is not sent to the calculator. Pickup points are loaded from the dedicated service-points endpoint after the customer selects an office or locker rate.
- Calculator debug is opt-in: when address line 2 is exactly `DEBUG`, the calculator payload includes `debug: true` and `clearCache: true`, and request/response diagnostics are logged under WooCommerce log source `octavawms`, subsystem `checkout_calculator`.
- With the same `DEBUG` address-line-2 trigger, service-point backend requests are logged under subsystem `checkout_service_points`.

## Rate display

Each calculator rate becomes one Woo shipping option. The label is normalized for checkout:

- Default carrier service names such as `СТАНДАРТ 24 ЧАСА` are suppressed.
- The delivery target is shown instead: `ДО АДРЕС`, `ДО ОФИС`, or `ДО АВТОМАТ`.
- Example labels: `Speedy - ДО АДРЕС`, `Speedy - ДО ОФИС`, `Speedy - ДО АВТОМАТ`.
- The carrier logo is prepended when the calculator response includes `deliveryServices[].logo` or `deliveryService.logo`.
- The Woo-added colon before the price is removed for this shipping method only.

## Pickup-point search

Office and locker rates require a pickup point before the order can be placed. The checkout UI loads points through:

```text
POST admin-ajax.php?action=octavawms_checkout_service_points
```

Search is always constrained by:

- selected delivery service/carrier id,
- checkout locality id,
- point type (`service_point` for office, `self_service_point` for locker).

The locality id is resolved from the checkout postcode through `/api/delivery-services/postcodes` with an `extId` filter matching the postcode, for example `extId=9002`. The embedded postcode locality is then used as the `locality` filter for service-point search. If locality cannot be resolved, the plugin returns no pickup results instead of running a broad global service-point search.

The pickup selector includes:

- a text search field that re-queries the service-points endpoint with the selected carrier/locality/type filters,
- a `Near me` button that asks the browser for location, forwards `lat`/`lng` to the service-points endpoint with `sort=distance`, and also sorts returned points client-side when coordinates are present,
- a `Map` view using Leaflet/OpenStreetMap, populated from the same filtered service-point response.

Service-point rows may include coordinates as `lat`/`lng`, `geo: "SRID=4326;POINT(lng lat)"`, or `geo: "lng,lat"`. The checkout AJAX response normalizes these to `lat` and `lng` for the browser UI.

During Woo checkout recalculation, the shipping block and place-order action are disabled to avoid submitting stale rate or pickup-point data.

## Izprati attribution

For Izprati-branded installs, the checkout shipping heading shows the same small attribution pattern as the Shopify pre-checkout widget: `Работи с ИЗПРАТИ.БГ` with the paper-plane mark. This is controlled by a `showIzpratiAttribution` flag passed to checkout JavaScript.

The future backend plan endpoint should replace `CheckoutDeliveryService::hasRemoveBrandingPlan()`. Until that endpoint exists, Woo treats remove-branding as inactive for every installation, so Izprati-branded installs show the attribution.

## Cash on delivery rules

Woo settings include a `Cash on delivery rules` matrix for the OctavaWMS shipping method. Rules can hide or allow the Woo `cod` payment gateway by:

- delivery service id,
- delivery type (`Address`, `Office`, `Locker`, or `Office or locker`),
- exact OctavaWMS rate id.

Rules are stored locally in the Woo integration setting `cod_visibility_rules_json` using the same shape as the Shopify payment customization rules: `payment_handle: "cod"`, `mode: "exclude"` or `mode: "include"`, and a `match` object with `delivery_service_id`, `delivery_type`, and/or `rate_id`.

When checkout renders payment methods, the plugin evaluates only selected `delivery_with_orderadmin:*` rates. More specific matches override broader ones, so a broad rule can hide COD for lockers while a rate-level `include` rule allows COD for one specific locker rate.

## Order persistence

On checkout, the selected delivery data is stored on the Woo shipping item using the exact meta keys consumed by the OctavaWMS WooCommerce integration:

```text
deliveryService
rate
servicePoint
```

The plugin also writes diagnostic order meta:

```text
_octavawms_delivery_rate_id
_octavawms_delivery_service
_octavawms_delivery_rate
_octavawms_service_point
```

The server-side `integration-woocommerce` package gives these shipping-line meta values precedence over carrier mapping.
