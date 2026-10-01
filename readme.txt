=== Изпрати.БГ Shipping for WooCommerce ===
Contributors: tagontrack
Tags: shipping, fulfillment, labels, pickup points, couriers
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.6.1
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

Use the WooCommerce Marketplace support channel for this extension. General Изпрати.БГ documentation is available at https://izprati.bg/docs/.

== Changelog ==

= 1.6.1 - 2026-10-02 =

* Fixed QIT security findings for request sanitization, output handling, prepared SQL, label file paths, and catalog loading.
* Added WordPress and WooCommerce tested-version headers required by Marketplace validation.

= 1.6.0 - 2026-10-02 =

* Added Изпрати.БГ Marketplace branding and first-run connection defaults.
* Added explicit HPOS and Cart and Checkout blocks compatibility declarations.
* Changed the extension license to GPL-2.0-or-later.
* Added Marketplace-ready packaging and version validation.
