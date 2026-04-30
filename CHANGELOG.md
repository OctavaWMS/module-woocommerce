# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- `OctavaWMS\WooCommerce\PluginLog` — WooCommerce logger (`octavawms-connect` source) for failed connect attempts; logs **request headers** (Authorization redacted), **response headers** (Set-Cookie redacted), response body, and JSON when parseable. README documents **WooCommerce → Status → Logs** and `wp-content/uploads/wc-logs/` paths.
- `OctavaWMS\WooCommerce\WooRestCredentials` — auto-discovers the OctavaWMS row in `wp_woocommerce_api_keys` (description `OctavaWMS%`), and builds an HMAC-signed `Authorization: OctavaWMS key_last7=…, ts=…, nonce=…, algo=HMAC-SHA256, signature=…` header for `/apps/woocommerce/connect` (used by `ConnectService` and `BackendApiClient::refreshBearerToken`).
- **OAuth bootstrap after connect:** when `/apps/woocommerce/connect` returns `status: ok` with `refreshToken` and `domain` (and no `apiKey`), `BackendApiClient::ingestConnectResponseArray` calls `Options::saveOAuthBootstrap` then `exchangeRefreshTokenForAccess()` (`POST …/oauth`, default client id `orderadmin`). `Options::mergeAccessTokenFromOAuth` persists the access token and optional rotated refresh token. Filters: `octavawms_oauth_url`, `octavawms_oauth_client_id`.
- `OctavaWMS\WooCommerce\Api\BackendApiClient` — REST calls to OctavaWMS (order lookup, shipments, import, label path), lazy `refreshBearerToken()` (OAuth refresh first, then signed re-connect), and 401 retry on `request()`.
- `OctavaWMS\WooCommerce\Admin\LabelMetaBox` — order edit meta box UI, styles, and JS panel.
- `OctavaWMS\WooCommerce\Admin\LabelAjax` — `octavawms_order_status` and `octavawms_upload_order` AJAX handlers.
- PHPUnit + Brain Monkey tests under `tests/` (including `OptionsTest` for OAuth-related option helpers).

### Changed
- `integration-woocommerce` backend: `ConnectController` parses the `OctavaWMS` auth scheme; `Registration::registerWooStore(array $data, string $httpBase, ?array $wcSig = null)` locates the Source by indexed `extId = substr(sha256(normalizedUrl), 0, 40)`, cross-verifies `auth.key`'s last 7 chars, HMAC-verifies the signature against the stored `auth.secret`, mints `pluginApiKey` on the matching Source, and skips user/domain creation. Rejects signatures with skew greater than 300s.
- `LabelService` moved to `OctavaWMS\WooCommerce\Api\LabelService`; label HTTP is performed via `BackendApiClient` (default host from `Options::DEFAULT_API_BASE` + `LABEL_PATH` when the label endpoint field is empty).
- `AdminLabelActions` is a thin orchestrator; meta box and AJAX live under `src/Admin/`.
- Admin notice on WooCommerce settings (`Notices`): suggests Connect when no credentials are stored; treats a stored OAuth **refresh token** as configured so the notice does not flash between connect and token exchange.

### Removed
- Temporary **REST API consumer key / secret** integration fields and `Options::getConnectAuthorizationHeader()` (replaced by `WooRestCredentials` auto-discovery).

### Fixed
- Order edit panel showed “not in OctavaWMS” after a successful import when the list API used a different HAL `_embedded` key (`orders` / single `order` object) or when `_octavawms_external_order_id` did not match the backend’s canonical `extId`. The plugin now parses several collection shapes, tries multiple Woo identifiers (meta, order key, numeric id, order number), syncs `_octavawms_external_order_id` from the API when found, and reads `extId` from import responses when present.

### Notes (release checklist)
- `Options::DEFAULT_API_BASE` currently points at **`https://alpha.orderadmin.eu`** while the signed connect and OAuth paths are verified end-to-end; revert to **`https://pro.oawms.com`** before production release. The **Connect service URL** filter/setting accepts a bare base (e.g. `https://alpha.orderadmin.eu`) as well as the full `/apps/woocommerce/connect` URL.

## [1.0.0] — 2025

Initial public release: connect, label generation, order actions, downloads.
