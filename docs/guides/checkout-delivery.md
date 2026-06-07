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
  "debug": true,
  "clearCache": true,
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
- `debug` and `clearCache` are currently enabled so backend calculator diagnostics are visible while the feature is being validated.
- Calculator request and response are logged under the WooCommerce log source `octavawms`, subsystem `checkout_calculator`. Decoded JSON responses are logged under `response.json`; duplicate raw response bodies and exact `raw` fields are omitted from logs.

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

The locality id is resolved from the checkout postcode through `/api/locations/localities` before pickup search. If locality cannot be resolved, the plugin returns no pickup results instead of running a broad global service-point search.

During Woo checkout recalculation, the shipping block and place-order action are disabled to avoid submitting stale rate or pickup-point data.

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
