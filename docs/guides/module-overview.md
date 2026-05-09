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
| Bootstrap | `octavawms.php` |
| REST + label HTTP | `src/Api/BackendApiClient.php`, `src/Api/LabelService.php` |
| Order admin UI | `src/Admin/LabelMetaBox.php`, `src/Admin/LabelAjax.php`, `src/AdminLabelActions.php` |
| Settings + connect | `src/SettingsPage.php`, `src/ConnectService.php` |
| Woo REST key discovery + HMAC | `src/WooRestCredentials.php` |
| Options + defaults | `src/Options.php` |
| Auto-import hooks | `src/OrderSyncService.php` |
| Logging / user-facing errors | `src/PluginLog.php` |
| Tenant copy (gettext catalogs) | `src/I18n/BrandedStrings.php`, `src/I18n/catalogs/*.php` |

## Quality & tests

```bash
composer install
composer check   # php-lint + phpunit (see composer.json scripts)
```

Tests use **PHPUnit 11** and **Brain Monkey**. Details: root [README.md — Running tests](../../README.md#running-tests).

## Release

`./release.sh` from the plugin root (semver, `composer check`, tag, push). See root README **Releasing** section.

## ClickUp & agents

Project conventions for commits, ClickUp list/tag, and task lifecycle: [clickup-workflow.md](clickup-workflow.md) and root [AGENTS.md](../../AGENTS.md).

## Troubleshooting (admin)

- **“Login to panel” from the order edit screen fails with an invalid nonce / 403 JSON** (older setups may have shown raw `-1` from `admin-ajax.php`): reload the order page so `octavawmsOrderPanel.panelLoginNonce` matches the current session. The plugin also refreshes that nonce on the WordPress admin heartbeat for users who can `manage_woocommerce`.
- **Full-page or HTML caching of wp-admin**: exclude order edit URLs (for example `post.php` with `shop_order`, and `admin.php?page=wc-orders&action=edit`) from cache. Cached HTML can embed a stale localized nonce while cookies still identify the user, which breaks AJAX until the page is uncached or bypassed.
