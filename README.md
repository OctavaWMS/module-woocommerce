# OctavaWMS Connector (WooCommerce)

WordPress + WooCommerce plugin that **connects your store to OctavaWMS**. It includes **shipping label** generation and download, **one-click connect** to provision credentials on the OctavaWMS cloud, an **order edit panel** that syncs status with the backend, and is structured so we can add more features over time.

## Documentation

- **[Documentation index](docs/README.md)** — module overview, merchant-facing guides, links to deeper topics.
- **[Agent / ClickUp conventions](AGENTS.md)** — commits, `composer check`, task list and tag (for contributors and coding agents; not included in the distribution zip).

## Requirements

- WordPress 6.0+
- WooCommerce 7.1+ (recommended: enables HPOS compatibility for order edit links)
- PHP 8.1+
- HTTPS in production (required for the one-click connect flow, except on localhost)
- Composer (recommended): run `composer install` in the plugin directory for PSR-4 autoload and dev tools.

## Installation

1. Copy this folder to `wp-content/plugins/octavawms-woocommerce` (or your chosen slug).
2. From the plugin directory: `composer install` to generate `vendor/autoload.php`. Without Composer, the plugin loads a built-in `require` list of classes from `octavawms-woocommerce.php`.
3. In WordPress, activate **OctavaWMS Connector** under *Plugins*.

## One-click connect

1. Go to **WooCommerce → Settings → Integrations → OctavaWMS Connector**.
2. Click **Connect to OctavaWMS**. The plugin will POST to `https://pro.oawms.com/apps/woocommerce/connect` by default (`Options::DEFAULT_API_BASE` + `/apps/woocommerce/connect`), or — if **API base URL (override)** is set under Advanced — to `{that base}/apps/woocommerce/connect`. After connect, REST calls normally follow the **label endpoint’s host** saved by successful connect; an override wins over that host. Filters: `octavawms_default_connect_url` then `octavawms_connect_url` (same pattern as elsewhere).
3. On success, the plugin stores the **label endpoint**, **source id**, and either a long-lived **API key** (`apiKey`) or, when the cloud returns OAuth fields, a **refresh token** and **domain** plus a follow-up exchange that writes the **Bearer access token** into the same integration option. Click **Save changes** on the form if the fields are open.

**Auto-authentication via the existing OctavaWMS WooCommerce REST key (no input needed)**

When OctavaWMS was installed earlier via the in-panel `wc-auth/v1/authorize` flow, WooCommerce created a REST key labelled **`OctavaWMS - API (…)`** under **WooCommerce → Settings → Advanced → REST API**. The plugin auto-discovers that row and, on connect, sends a signed header:

```
Authorization: OctavaWMS key_last7=<last 7 chars of ck_…>, ts=<unix>, nonce=<hex>, algo=HMAC-SHA256, signature=<base64 HMAC-SHA256(ts.nonce.bodyJson, consumer_secret)>
```

OctavaWMS looks up the existing `Source` by its indexed **`extId = substr(sha256(normalizedUrl), 0, 40)`** (e.g. `https://ironlogic.bg` → `6cb806a1fc9af36aaea72873512fdd4e2eca590c`), cross-verifies that the stored `auth.key` ends with the sent `key_last7`, recomputes the HMAC against its stored `auth.secret`, and returns the `pluginApiKey`. No merchant input is required, and the consumer secret never travels over the wire. Logs record the header as `OctavaWMS key_last7=…, ts=…, nonce=…, algo=HMAC-SHA256, signature=[redacted]`.

### Connect troubleshooting and logs

If **Connect to OctavaWMS** fails, the plugin writes **WooCommerce log** lines (source **`octavawms-connect`**) with the connect URL, a **redacted** JSON body (admin email masked), **outgoing request headers** (Authorization redacted), **HTTP response status**, **response headers** (Set-Cookie redacted), **response body** (truncated), and parsed **`response_json`** when the body is valid JSON.

**Where to read them**

| Location | How to find it |
|----------|----------------|
| **WordPress admin** | **WooCommerce → Status → Logs**. Open the log whose name starts with **`octavawms-connect`** (newest first), or use the log viewer if your WooCommerce version lists logs by handler/source there. |
| **On disk** | Plain text files under your WordPress **uploads** directory, usually **`wp-content/uploads/wc-logs/`**. Filenames look like **`octavawms-connect-YYYY-MM-DD-….log`**. On **multisite**, the path is under that site’s uploads folder (e.g. `wp-content/uploads/sites/N/wc-logs/`). |

The exact uploads path follows `wp_upload_dir()` (filters such as `upload_dir` can change it). Logging requires WooCommerce’s logger (`wc_get_logger()`); if WooCommerce is inactive, no file is written.

## Manual (advanced) configuration

If you are self-hosted or were given values by your operator:

| Setting | Description |
|--------|-------------|
| **API base URL (override)** | Optional (`WooCommerce → Settings → Integrations → OctavaWMS`). When set — e.g. `https://staging.example.com` — all API, OAuth, connect, and label traffic uses that scheme and host (**highest** priority). When empty: after connect the **host from Label endpoint** is used; otherwise the default **`https://pro.oawms.com`**. |
| **Label endpoint URL** | Filled automatically by connect (or historically). Its **host** becomes the API base when no override is set (and when this field has a URL). Labels post to `{host}/apps/woocommerce/api/label` unless overridden by connect response paths. |
| **API key** | Bearer access token sent as `Authorization: Bearer …` after Connect (or manual paste). When Connect returns OAuth bootstrap fields, the access token is obtained via `POST /oauth` and may be rotated with a new refresh token. |
| **Auto-sync new orders** | When enabled (default), new orders trigger `POST /api/integrations/import` so OctavaWMS can create them without using **Upload order** in the admin. |
| **Auto-sync order updates** | When enabled (default), order saves and status changes re-import the order. A short debounce (transient, 10 seconds) plus per-request deduplication reduces duplicate API calls when WooCommerce fires several hooks for one change. |

## Carrier meta mapping (WooCommerce → OctavaWMS)

When a WooCommerce checkout plugin writes courier information into order meta (e.g. `courierName`, `courierID`, `delivery_type`), the **Carrier meta mapping** table lets you translate those values into the OctavaWMS **delivery service integration**, **pickup type**, and optional **rate** that should be used when the order is imported.

### Where to configure it

**WooCommerce → Settings → Integrations → OctavaWMS Connector → Carrier meta mapping (Woo → OctavaWMS)**

Each row defines one rule:

| Column | Description |
|--------|-------------|
| **WC meta key** | Order meta key to match (e.g. `courierName`). |
| **WC meta value** | Exact string the meta must equal (e.g. `Speedy`). |
| **WC delivery_type** *(optional)* | If your checkout also writes a `delivery_type` meta key, add its expected value here (e.g. `office`). Leave blank to match any delivery type. |
| **Strategy for AI** | OctavaWMS pickup strategy passed to the AI router: `address` / `office` / `locker` / `office_locker`. |
| **Carrier** | OctavaWMS delivery service integration (search by name). |
| **Rate** | Specific rate within that integration (optional). |

Rules are evaluated in order; the first match wins.

### Example — Speedy "до офис" from a real order

In a typical WooCommerce order (example: checkout writes courier meta on order `#49250`) you might see:

```
meta_data:
  delivery_type = "office"
  courierID     = "18"
  courierName   = "Speedy"
```

To route all such orders to **Speedy delivery service integration #23** (office pickup), add this row (or paste the JSON in *Switch to JSON* mode):

```json
[
  {
    "courierMetaKey": "courierName",
    "courierMetaValue": "Speedy",
    "wooDeliveryType": "office",
    "type": "office",
    "deliveryService": 23,
    "rate": null
  }
]
```

**How it works at runtime:** when the order is imported (`POST /api/integrations/import`), OctavaWMS reads the mapping from the integration source settings and resolves carrier + type for each order before creating the delivery request. No extra action is needed after saving the mapping.

**Alternative key — `courierID`:** if the store uses numeric courier IDs you can match on those instead (or add a second row as a fallback):

```json
[
  {
    "courierMetaKey": "courierName",
    "courierMetaValue": "Speedy",
    "wooDeliveryType": "office",
    "type": "office",
    "deliveryService": 23,
    "rate": null
  },
  {
    "courierMetaKey": "courierID",
    "courierMetaValue": "18",
    "wooDeliveryType": "office",
    "type": "office",
    "deliveryService": 23,
    "rate": null
  }
]
```

> **Tip:** to find your delivery service integration IDs, open the **Carrier** dropdown in the visual editor and search by courier name — the ID appears in brackets next to the name.

## Automatic order sync (WooCommerce → OctavaWMS)

After Connect (so **source id** and **Bearer** are present), the plugin can push orders automatically:

- **New orders:** `woocommerce_checkout_order_processed` and `woocommerce_new_order` run the same import path. Only one import runs per order per HTTP request if both hooks fire.
- **Updates:** `woocommerce_update_order` and `woocommerce_order_status_changed` run when **Auto-sync order updates** is on. The same debouncing applies.

Turn either option off under **WooCommerce → Settings → Integrations → OctavaWMS Connector** (checkboxes in the Advanced section). If import fails, check **WooCommerce → Status → Logs** for entries from source **`octavawms`** with subsystem **`order_auto_sync`**.

## Order edit — OctavaWMS panel

On the order edit screen (classic `shop_order` or HPOS), a meta box **OctavaWMS Connector** loads backend state via AJAX:

- If the order is **not** in OctavaWMS yet, you can **Upload order** (requires a stored **source id** from Connect).
- When shipments exist, the panel shows **shipment id** and **state**, **places (boxes)** with weights and dimensions, and **Edit shipment** (carrier, recipient locality, service point, strategy for AI) using the same backend concepts as the Shopify app’s edit-shipment flow.
- When the shipment state is **`pending_error`**, a full-width banner appears **above** the two columns (Create label | Edit shipment). The message is taken from the API in the same order as the Shopify UI: structured **`errors`** first (strings or objects with `message` / `code`), then **`deliveryServiceStatus`** (e.g. carrier-side text such as “Sender profile should be set”), then generic problem fields (`message`, `detail`, …) via `PluginLog::userMessageFromApiJson`.
- **Generate / Re-generate label** (same flow as Order actions). If the backend has no preprocessing **queue** yet for that delivery request, the plugin **POST**s **`/api/delivery-services/preprocessing-queue`** with `name` / `sender` only (`BackendApiClient::createProcessingQueueForSender`). **`deliveryRequest` is sent only on `preprocessing-task`**, not on queue create. Then the plugin resolves the queue (or uses the create response) before creating/updating the task (see API table below).
- When a label is stored on the order, **Download label** is shown.

You can still use **Order actions → Generate shipping label → Update** as before.

## API endpoints used (reference)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/products/order` | Check if an order exists in OctavaWMS (`extId` filter). |
| `GET` | `/api/delivery-services/requests` | List delivery requests / shipments for `extId` (and related filters). |
| `GET` | `/api/delivery-services/requests/{id}` | Single delivery request (shipment) for the order panel and label pipeline. |
| `GET` | `/api/delivery-services/delivery-request-service` | `action=tasks` — resolve existing preprocessing **task** and **queue** ids for a delivery request. |
| `POST` | `/api/delivery-services/preprocessing-queue` | Create sender-scoped queue when none exists (`name`, optional `sender` id only — **not** `deliveryRequest`; ties to shipments only via **preprocessing-task**). |
| `POST` / `PATCH` | `/api/delivery-services/preprocessing-task` | Create or update preprocessing task (`state: measured`, dimensions, `queue`); may return PDF/HTML synchronously or a task id for polling. |
| `PATCH` | `/api/delivery-services/requests/{id}` | Update shipment fields (e.g. service point, carrier, locality, EAV) from **Edit shipment**. |
| `POST` | `/api/integrations/import` | Push the WooCommerce order into OctavaWMS (uses **source id** from Connect). |
| `GET` | `/api/integrations/sources/{id}` | Load integration source `settings` (including `DeliveryServices.options.carrierMapping` for the carrier matrix). |
| `PATCH` | `/api/integrations/sources/{id}` | Persist `settings` after **Save mapping** in the integration screen. |
| `POST` | `/apps/woocommerce/api/label` | Request a label PDF/JSON for `externalOrderId` (resolved host via **API base override**, label-endpoint host, or **`https://pro.oawms.com`** default). |
| `POST` | `/oauth` | Exchange `refresh_token` + `domain` (+ `client_id`) for an `access_token` when Connect returns OAuth bootstrap instead of `apiKey`. |

## Order metadata

| Meta key | Purpose |
|----------|---------|
| `_octavawms_external_order_id` | If set, preferred for import filters and tried first for GET lookups. If empty or wrong, the plugin also tries the **order key**, numeric **order id**, and **order number** until the order list API returns a match; it may update this meta from the backend’s `extId` after a successful lookup or import response. |
| `_octavawms_label_url` / `_octavawms_label_file` | Written when a label is generated: remote URL and/or a local file path in `uploads/octavawms-labels/`. Do not set these manually. |

## Label files on disk

On activation, the plugin creates (if possible) a `.htaccess` in `wp-content/uploads/octavawms-labels/` to block direct web access. On **Nginx**, deny direct access to that directory yourself (e.g. `location ~* /octavawms-labels/` → `return 404` or `deny all` as appropriate for your config).

## PHP layout (developer)

| Area | Namespace / path |
|------|-------------------|
| Bootstrap | `octavawms-woocommerce.php` |
| REST + label HTTP | `src/Api/BackendApiClient.php`, `src/Api/LabelService.php` |
| Order UI | `src/Admin/LabelMetaBox.php`, `src/Admin/LabelAjax.php` |
| Order actions / generation orchestration | `src/AdminLabelActions.php` |
| Woo order → OctavaWMS extId candidates | `src/WooOrderExtId.php` |
| Settings / connect + carrier matrix assets | `src/SettingsPage.php`, `src/ConnectService.php`, `assets/js/admin-settings-matrix.js` |
| Integration settings AJAX | `src/Admin/SettingsAjax.php` |
| WooCommerce REST key auto-discovery + HMAC signer | `src/WooRestCredentials.php` |
| Connect debug logging (`octavawms-connect` WC log source) | `src/PluginLog.php` |
| Admin notices (missing integration hint on WC settings) | `src/Notices.php` |
| Options | `src/Options.php` |
| Tenant branding (domain hints) | `src/UiBranding.php` |
| English msgids → tenant copy (`gettext`), catalogs | `src/I18n/BrandedStrings.php`, `src/I18n/catalogs/*.php` |
| Auto-import on create/update | `src/OrderSyncService.php` |

## Running tests

```bash
composer install
composer test
# or
composer check   # php-lint + phpunit
```

## Releasing

From the plugin root, run `./release.sh` (same flow as `integration-shopify/release.sh`: semver tag choice, `composer check`, `composer validate --strict`, tests, merge `main` into `release/1.x`, annotated tag, push). Use `./release.sh --help` for options (`--yes`, `--remove-last`, explicit version).

After the tag is pushed, the script runs **`scripts/build-plugin-zip.sh`**, which produces **`dist/octavawms-woocommerce-<version>.zip`**: a folder **`octavawms-woocommerce/`** suitable for upload to WordPress. Omitted from the archive: **`vendor/`**, **`tests/`**, **`dev/`**, **`scripts/`**, **`release.sh`**, **`.cursor/`**, **`phpunit.xml.dist`**, internal-only **`docs/guides/clickup-workflow.md`** and **`AGENTS.md`**, and similar dev noise (e.g. `.phpunit.cache/`). To build a zip without a full release, run `./scripts/build-plugin-zip.sh <version-or-tag>` from a git checkout that still contains `scripts/`.

Tests use **PHPUnit 11** and **Brain Monkey** to stub WordPress functions. Notable suites: `tests/Api/BackendApiClientTest.php` (HTTP clients), `tests/Api/LabelServiceTest.php` (label + queue bootstrap), `tests/Admin/LabelAjaxTest.php` (shipment detail payload / admin AJAX), `tests/OrderSyncServiceTest.php` (auto-sync hooks, debouncing, extId persistence), `tests/PluginLogTest.php` (error message shaping, including `deliveryServiceStatus`). `tests/bootstrap.php` defines a minimal `WC_Order` stub and `wc_get_order()` for tests where WooCommerce is not loaded. See `phpunit.xml.dist`.

## What is **not** in this plugin (vs a full Shopify app)

- Bulk label actions on the order list
- In-admin service point and parcel/place management blocks
- Storefront or checkout customisation, post-thank-you rate pickers, or theme popups
- Shopify Functions (delivery “hide / rename” rules) — not applicable to Woo
- **Third-party IdP SSO for merchants** — not in scope; API auth is either a stored plugin API key or refresh-token exchange to OctavaWMS OAuth (`POST /oauth` on the configured API host)

## Developer filters

- `octavawms_default_connect_url` — default one-click **`POST`** connect URL (**full URL** ending in `/apps/woocommerce/connect`; default is `Options::getBaseUrl()` + that path unless you replace the filtered string entirely).
- `octavawms_connect_url` — full connect URL, receives `( $url, $home_url )`.
- `octavawms_require_https_for_connect` — `bool` (default `true` except localhost) to require HTTPS for connect.
- `octavawms_oauth_url` — full URL for `POST /oauth` (default: API base + `/oauth`).
- `octavawms_oauth_client_id` — OAuth client id for the refresh grant (default: `orderadmin`).
- `octavawms_panel_app_base` — origin for **Login to the panel** (default: `https://app.izprati.bg`, no trailing slash).
- `octavawms_brand_pack` — filter the detected tenant id (`null` | `"izprati"` | …) after `{oauth_domain}` / API-host hints; receives `( $detected, $hints )`.

## Tenant branding / translations

- **`UiBranding`** infers an internal pack id (e.g. **izprati**) from **`oauth_domain`** and the **`label_endpoint`** host (`izprati.bg`, slug `izpratibg`, …).
- All user-visible strings remain **canonical English** wrapped in **`__('…', 'octavawms')`** in PHP so standard WordPress translation files (`languages/octavawms-*.mo`) still apply.
- For white-label installs, **`I18n\BrandedStrings`** hooks **`gettext`** for domain `octavawms` and replaces msgids listed in **`src/I18n/catalogs/{pack}-bg.php`** (e.g. `izprati-bg.php`). Add a sibling catalog and extend **`BrandedStrings::catalogPaths()`** for new tenants.

Built-in defaults (no filter required): `Options::DEFAULT_API_BASE` (**`https://pro.oawms.com`**) for API base when neither **API base override** nor label-endpoint host applies; optional WooCommerce integration field **`api_base`** overrides that host (`BackendApiClient::LABEL_PATH` is still used relative to that base when connect does not supply a full label URL).

## OctavaWMS (cloud) components

In the [integration-woocommerce](https://github.com/OctavaWMS/integration-woocommerce) package:

- `POST /apps/woocommerce/connect` — registers the store, creates/updates a source, issues `auth.pluginApiKey` in protected settings, returns `labelEndpoint` pointing to `.../api/label`.
- `POST /apps/woocommerce/api/label` — `Authorization: Bearer <pluginApiKey>`; forwards the body to the URL in application config `octavawms_woocommerce.label_proxy_url` when the label backend is on another host. Configure that in the host application.

## License

Proprietary (OctavaWMS).
