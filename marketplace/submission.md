# WooCommerce Marketplace submission

## Product identity

- **Name:** Изпрати.БГ Shipping for WooCommerce
- **Product type:** Extension
- **Vendor:** TagOnTrack
- **Website:** https://izprati.bg/
- **Documentation:** https://izprati.bg/docs/
- **Privacy:** https://izprati.bg/privacy-policy/
- **Terms:** https://izprati.bg/terms-conditions/
- **Version:** 1.6.0
- **License:** GPL-2.0-or-later
- **Package:** `dist/izprati-bg-shipping-1.6.0.zip`

## Short description

Connect WooCommerce to Изпрати.БГ for live carrier delivery options, office and locker selection, automatic order synchronization, and shipping-label creation.

## Merchant value

Изпрати.БГ Shipping for WooCommerce brings Bulgarian multi-carrier shipping into the WooCommerce workflow. Merchants can show calculated delivery options at checkout, let shoppers select supported carrier offices or lockers, synchronize orders automatically, and create or print labels without re-entering order details.

The extension supports classic checkout, WooCommerce Cart and Checkout blocks, and High-Performance Order Storage (HPOS). It is intended for Bulgarian merchants that use an Изпрати.БГ account and their own applicable carrier agreements.

## Key features

- Live delivery options calculated from the connected Изпрати.БГ account.
- Carrier office and locker selection during checkout.
- Automatic synchronization of new and updated WooCommerce orders.
- Label creation, download, and bulk-print workflows from WooCommerce orders.
- Shipment status, parcel, service point, and carrier details in the order screen.
- Support for classic checkout, Cart and Checkout blocks, and HPOS.
- Secure account connection without asking merchants to paste service credentials.

## External service and monetization disclosure

The extension connects to the hosted Изпрати.БГ shipping service at `api.izprati.bg`. An active Изпрати.БГ account is required. Service-plan fees and carrier charges, if applicable, are contracted and billed separately from WooCommerce Marketplace. The extension itself does not collect payment or expose an in-plugin purchase flow.

This externally billed service model requires WooCommerce approval under a Partnership Agreement before Marketplace publication. Do not represent the submission as Marketplace-billed or free unless the commercial model changes.

## Why it belongs in the Marketplace

The extension addresses a region-specific fulfillment need that generic international shipping extensions do not cover well: a Bulgarian merchant can work with multiple supported carriers, carrier offices, and parcel lockers through one Изпрати.БГ connection. It keeps delivery selection and fulfillment actions in WooCommerce while using the merchant's existing Изпрати.БГ and carrier relationships.

## Reviewer testing instructions

Prerequisites:

1. WordPress 6.0 or newer.
2. WooCommerce 7.1 or newer.
3. PHP 8.1 or newer.
4. A reviewer-specific Изпрати.БГ test account. Supply credentials only through the private Vendor Dashboard reviewer field; never add them to this repository or the distributable ZIP.

Install and connect:

1. Upload `izprati-bg-shipping-1.6.0.zip` in **Plugins > Add New > Upload Plugin**.
2. Activate **Изпрати.БГ Shipping for WooCommerce**.
3. Go to **WooCommerce > Settings > Integrations > Изпрати.БГ**.
4. Select **Connect to Изпрати.БГ** and complete the reviewer test-account connection.
5. Leave **API base URL (override)** empty so the branded production/test routing returned by the connection is used.

Core checks:

1. Add a physical product with a weight and dimensions, then add it to the cart.
2. Enter a Bulgarian delivery address and confirm that available carrier delivery options appear.
3. Select an office or locker option and confirm that checkout requires and saves a pickup point.
4. Place the order and confirm it is synchronized to Изпрати.БГ.
5. Open the WooCommerce order and confirm the Изпрати.БГ panel shows the synchronized shipment.
6. Generate a shipping label and confirm the authenticated download works.
7. Enable HPOS and repeat the order-screen check.
8. Use a block-based Cart and Checkout page and repeat the delivery-option and pickup-point checks.

Expected network access:

- `https://api.izprati.bg` for account connection, delivery calculations, synchronization, shipment operations, and labels.
- The connected WordPress site's own REST API during the secure WooCommerce authorization flow.

## Compatibility declarations

- WordPress: 6.0+
- WooCommerce: 7.1+
- PHP: 8.1+
- HPOS: declared compatible
- Cart and Checkout blocks: declared compatible

## Support response

Marketplace customers should use the support channel attached to the WooCommerce Marketplace product. General service documentation is available at https://izprati.bg/docs/.

## Partnership request draft

Subject: Partnership Agreement request — Изпрати.БГ Shipping for WooCommerce

Hello WooCommerce Partnerships team,

TagOnTrack is preparing **Изпрати.БГ Shipping for WooCommerce** for submission to the WooCommerce Marketplace. The GPL-2.0-or-later extension connects Bulgarian merchants to the hosted Изпрати.БГ multi-carrier shipping service for checkout delivery options, office and locker selection, order synchronization, and label creation.

The WooCommerce vendor account is held by TagOnTrack. The public Изпрати.БГ service terms and privacy policy identify Лимон ЕООД as the service operator. We can provide the relevant authorization and legal-entity relationship details privately for your review.

An active Изпрати.БГ service account is required. Service-plan fees and carrier charges, where applicable, are contracted and billed outside WooCommerce Marketplace; the extension does not contain an in-plugin checkout or collect payment. We would therefore like to request review and approval of a Partnership Agreement for this external-service model before submitting the product.

Product website: https://izprati.bg/
Documentation: https://izprati.bg/docs/
Privacy: https://izprati.bg/privacy-policy/
Terms: https://izprati.bg/terms-conditions/

Please let us know what commercial, legal-entity, technical, or data-flow details you need for the agreement and product review.

Kind regards,
TagOnTrack

## Items that must remain private

- Reviewer account credentials.
- API keys, OAuth tokens, carrier credentials, and signed URLs.
- Customer/order/label data.
- Any legal-entity evidence requested by WooCommerce.

## Submission gates

- WooCommerce confirms the external-billing Partnership Agreement path.
- The submitting Vendor profile has a valid public privacy-policy URL and terms URL.
- Reviewer test credentials are available privately.
- Product ZIP passes WooCommerce QIT and manual review.
- A human explicitly confirms sending the partnership request and, later, the final product submission.
