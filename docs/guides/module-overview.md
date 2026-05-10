# OctavaWMS WooCommerce **module** (WordPress plugin)

This repository is **`octavawms/module-woocommerce`**: a **WordPress + WooCommerce** plugin that connects a store to OctavaWMS (connect flow, order sync, shipping labels, order-edit panel). It is **not** the Zend **`integration-woocommerce`** package that runs inside the OctavaWMS application server.

## Relationship to the cloud integration

| Piece | Repository / role |
|--------|-------------------|
| Store-side plugin (this repo) | **`module-woocommerce`** — `POST /apps/woocommerce/connect`, REST + label calls from WordPress |
| Server-side WooCommerce integration | **[integration-woocommerce](https://github.com/OctavaWMS/integration-woocommerce)** — routes, webhooks, order import, label proxy |

High-level connect and API behaviour are summarised in the root [README.md](../../README.md) (one-click connect, HMAC header, OAuth refresh, logs). For **panel** HTTP routes and webhooks, use **`integration-woocommerce`** docs: [`HTTP_AND_WEBHOOKS.md`](https://github.com/OctavaWMS/integration-woocommerce/blob/main/docs/guides/api/HTTP_AND_WEBHOOKS.md).

## Requirements

- WordPress 6.0+, WooCommerce 7.1+ (HPOS-friendly), PHP 8.1+
- Composer recommended for PSR-4 autoload and tests

## PHP layout (by concern)

| Area | Primary paths |
|------|----------------|
| Bootstrap | `octavawms-woocommerce.php` |
| REST + label HTTP | `src/Api/BackendApiClient.php`, `src/Api/LabelService.php` |
| Order admin UI | `src/Admin/LabelMetaBox.php`, `src/Admin/LabelAjax.php`, `src/AdminLabelActions.php` |
| Settings + connect | `src/SettingsPage.php`, `src/ConnectService.php` |
| Integration settings AJAX (carrier matrix, rates, WC meta key suggestions) | `src/Admin/SettingsAjax.php` (`wp_ajax_octavawms_carrier_matrix`) |
| Woo REST key discovery + HMAC | `src/WooRestCredentials.php` |
| Options + defaults | `src/Options.php` |
| Auto-import hooks | `src/OrderSyncService.php` |
| Logging / user-facing errors | `src/PluginLog.php` |
| Tenant copy (gettext catalogs) | `src/I18n/BrandedStrings.php`, `src/I18n/catalogs/*.php` |

### Carrier meta mapping (admin)

Under **WooCommerce → Settings → Integrations → OctavaWMS Connector**, the carrier matrix appears **after** the standard integration fields (API base, API key, sync toggles, **Save changes**). It loads and saves `carrierMapping` on the OctavaWMS integration source at `settings.DeliveryServices.options.carrierMapping` (same shape as Orderadmin). The **Carrier** column uses WooCommerce **SelectWoo** (registered by Woo) for searchable delivery-service integrations; **WC meta key** can suggest keys from existing orders (HPOS `wc_orders_meta` or classic `shop_order` postmeta). Front-end: `assets/js/admin-settings-matrix.js`, enqueued from `ConnectService::maybeEnqueueConnectScript` (priority 20 so `selectWoo` is registered first).

## Quality & tests

```bash
composer install
composer check   # php-lint + phpunit (see composer.json scripts)
```

Tests use **PHPUnit 11** and **Brain Monkey**. Details: root [README.md — Running tests](../../README.md#running-tests).

## Release

`./release.sh` from the plugin root (semver, `composer check`, tag, push). After tagging it runs **`scripts/build-plugin-zip.sh`** (kept in git only; not inside the zip), which writes **`dist/octavawms-woocommerce-<version>.zip`** for merchant installs. **Excluded on purpose** (among others): `vendor/`, `tests/`, `dev/`, `scripts/`, `release.sh`, `.cursor/`, `docs/guides/clickup-workflow.md`, root **`AGENTS.md`**. See root README **Releasing** section.

## ClickUp & agents

Commits, list/tag, and task flow: root **[AGENTS.md](../../AGENTS.md)** and **[`.cursor/rules/`](../../.cursor/rules/)**. Optional prose mirror: [clickup-workflow.md](clickup-workflow.md) (not shipped in the distribution zip above).
