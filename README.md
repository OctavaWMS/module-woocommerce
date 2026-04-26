# OctavaWMS Connector (WooCommerce)

WordPress + WooCommerce plugin that **connects your store to OctavaWMS**. It already includes **shipping label** generation and download, **one-click connect** to provision credentials on the OctavaWMS cloud, and is structured so we can add more features over time.

## Requirements

- WordPress 6.0+
- WooCommerce 7.1+ (recommended: enables HPOS compatibility for order edit links)
- PHP 8.1+
- HTTPS in production (required for the one-click connect flow, except on localhost)
- (Optional) Composer, to use the PSR-4 autoloader: run `composer install` in the plugin directory.

## Installation

1. Copy this folder to `wp-content/plugins/octavawms-woocommerce` (or your chosen slug).
2. (Optional) From the plugin directory: `composer install` to generate `vendor/autoload.php`. If you skip this, the plugin still loads a built-in `require` list of classes.
3. In WordPress, activate **OctavaWMS Connector** under *Plugins*.

## One-click connect

1. Go to **WooCommerce → Settings → Integrations → OctavaWMS Connector**.
2. Click **Connect to OctavaWMS**. The plugin will POST to the connect URL (default: `https://pro.oawms.com/apps/woocommerce/connect` — override in **Connect service URL** or via the `octavawms_connect_url` filter).
3. On success, the **Label endpoint URL** and **API key** are stored; click **Save changes** on the form if the fields are open.

## Manual (advanced) configuration

If you are self-hosted or were given values by your operator:

| Setting | Description |
|--------|-------------|
| **Connect service URL** | Optional. Overrides the default one-click `POST` target. |
| **Label endpoint URL** | Full URL that accepts the label request (`POST` JSON `{"externalOrderId":"…"}`) and returns PDF, a JSON `labelUrl` / `labelBase64` payload, or a binary stream. |
| **API key** | Optional Bearer token sent as `Authorization: Bearer …` to the label endpoint. |

## Order metadata

| Meta key | Purpose |
|----------|---------|
| `_octavawms_external_order_id` | If set, sent as `externalOrderId` to the label API. If empty, the WooCommerce **order key** is used. |
| `_octavawms_label_url` / `_octavawms_label_file` | Written when a label is generated: remote URL and/or a local file path in `uploads/octavawms-labels/`. Do not set these manually. |

## Label files on disk

On activation, the plugin creates (if possible) a `.htaccess` in `wp-content/uploads/octavawms-labels/` to block direct web access. On **Nginx**, deny direct access to that directory yourself (e.g. `location ~* /octavawms-labels/` → `return 404` or `deny all` as appropriate for your config).

## What is **not** in this plugin (vs a full Shopify app)

- Bulk label actions on the order list
- In-admin service point and parcel/place management blocks
- Storefront or checkout customisation, post-thank-you rate pickers, or theme popups
- Shopify Functions (delivery “hide / rename” rules) — not applicable to Woo
- **Per-request OAuth refresh** — you get a long-lived API key; no rotating refresh token in the plugin

## Developer filters

- `octavawms_default_connect_url` — default one-click `POST` base URL.
- `octavawms_connect_url` — full connect URL, receives `( $url, $home_url )`.
- `octavawms_require_https_for_connect` — `bool` (default `true` except localhost) to require HTTPS for connect.

## OctavaWMS (cloud) components

In the [integration-woocommerce](https://github.com/OctavaWMS/integration-woocommerce) package:

- `POST /apps/woocommerce/connect` — registers the store, creates/updates a source, issues `auth.pluginApiKey` in protected settings, returns `labelEndpoint` pointing to `.../api/label`.
- `POST /apps/woocommerce/api/label` — `Authorization: Bearer <pluginApiKey>`; forwards the body to the URL in application config `octavawms_woocommerce.label_proxy_url` when the label backend is on another host. Configure that in the host application.

## License

Proprietary (OctavaWMS).
