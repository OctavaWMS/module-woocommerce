=== Изпрати.БГ Shipping for WooCommerce ===
Contributors: tasselchof
Tags: shipping, fulfillment, labels, pickup points, couriers
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Connect WooCommerce to Изпрати.БГ for carrier rates, pickup points, order synchronization, and shipping labels.

== Description ==

Изпрати.БГ Shipping for WooCommerce connects a WooCommerce store to the Изпрати.БГ shipping platform.

Merchants can:

* connect their store securely to an existing or new Изпрати.БГ account;
* synchronize WooCommerce orders with Изпрати.БГ;
* calculate carrier delivery options during checkout;
* let customers choose supported offices and lockers;
* create and download shipping labels from WooCommerce order screens;
* use both classic order storage and WooCommerce High-Performance Order Storage (HPOS);
* use the classic checkout and WooCommerce Cart and Checkout blocks.

An active Изпрати.БГ account and applicable carrier agreements are required. Service plans and carrier charges are managed separately through Изпрати.БГ.

Documentation: https://izprati.bg/docs/
Privacy: https://izprati.bg/privacy-policy/
Terms: https://izprati.bg/terms-conditions/

== External service and data disclosure ==

This plugin relies on the hosted Изпрати.БГ shipping service. The service website is https://izprati.bg/ and its API is hosted at https://api.izprati.bg/. The plugin cannot calculate connected carrier options, synchronize orders, manage shipments, or create labels without communicating with this service.

The following data is transmitted or made available to Изпрати.БГ when the corresponding feature is used:

* When a store administrator connects the plugin or refreshes its connection, the store URL, store name, and WordPress administrator email address are sent. If an existing WooCommerce REST connection is available, a public key suffix and cryptographic signature may be sent to match that connection; the WooCommerce consumer secret is not transmitted.
* When the connected store calculates delivery options, destination locality information such as country, city, and postcode is sent together with package weight, order-value estimate, selected sender, and configured carrier identifiers.
* When automatic synchronization is enabled, or an administrator manually synchronizes an order, the connected service retrieves the WooCommerce order through the authorized WooCommerce REST connection. This can include order identifiers and status, products, quantities, prices and totals, billing and shipping names, addresses, email address, phone number, and the selected carrier office or locker.
* When an administrator manages a shipment or creates a label, shipment identifiers, parcel dimensions and weight, sender, carrier, service point, and related fulfillment instructions are sent. Generated shipping labels are returned by the service.

Communication occurs after a store administrator connects the plugin and then when storefront delivery calculation, enabled order synchronization, or an administrator-requested shipment action requires it.

Use of the service is subject to the Изпрати.БГ [Terms and Conditions](https://izprati.bg/terms-conditions/) and [Privacy Policy](https://izprati.bg/privacy-policy/).

The optional pickup-point map uses OpenStreetMap tiles from https://tile.openstreetmap.org/. Tiles are requested only when the customer opens the map. The tile server receives the customer's IP address, browser request information, and requested map area. See the [OpenStreetMap Foundation Privacy Policy](https://osmfoundation.org/wiki/Privacy_Policy) and [Tile Usage Policy](https://operations.osmfoundation.org/policies/tiles/). Leaflet JavaScript, styles, and marker images are bundled locally with the plugin.

The "Near me" action requests browser location permission. If granted, latitude and longitude are sent to the connected shipping service to find nearby pickup points. The map may also show that location.

== Installation ==

1. Install and activate the extension.
2. Go to WooCommerce > Settings > Integrations > Изпрати.БГ.
3. Select Connect to Изпрати.БГ and complete the secure account connection.
4. Configure order synchronization, carrier mapping, checkout delivery, and label settings.
5. Place a test order before enabling the integration on a live checkout.

== Frequently Asked Questions ==

= Do I need an Изпрати.БГ account? =

Yes. The extension connects WooCommerce to the Изпрати.БГ service. You also need active agreements for the carriers you use.

= Does the extension support HPOS? =

Yes. The extension declares compatibility with WooCommerce High-Performance Order Storage.

= Does it support Cart and Checkout blocks? =

Yes. The extension supports both classic checkout and WooCommerce Cart and Checkout blocks.

= Where can I get help? =

Use the support channel where you obtained the plugin: its WordPress.org support forum or the WooCommerce Marketplace support channel. General Изпрати.БГ documentation is available at https://izprati.bg/docs/.

== Changelog ==

= 1.6.3 - 2026-10-09 =

* Bundled Leaflet locally and documented optional OpenStreetMap tile and browser-location use.
* Made checkout attribution an explicit merchant opt-in that is disabled by default.
* Corrected WordPress.org compatibility metadata and translation packaging.
* Verified compatibility metadata against WooCommerce 11.2.
* Aligned the text domain and package directory with the requested izprati-bulgaria-shipping slug.

= 1.6.2 - 2026-10-07 =

* Added the external-service and data-transfer disclosure required for WordPress.org publication.
* Added channel-neutral support guidance and WordPress.org publication packaging safeguards.

= 1.6.1 - 2026-10-02 =

* Fixed QIT security findings for request sanitization, output handling, prepared SQL, label file paths, and catalog loading.
* Added WordPress and WooCommerce tested-version headers required by Marketplace validation.

= 1.6.0 - 2026-10-02 =

* Added Изпрати.БГ Marketplace branding and first-run connection defaults.
* Added explicit HPOS and Cart and Checkout blocks compatibility declarations.
* Changed the extension license to GPL-2.0-or-later.
* Added Marketplace-ready packaging and version validation.
