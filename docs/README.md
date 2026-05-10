# OctavaWMS WooCommerce **module** documentation

WordPress + WooCommerce plugin package: **`octavawms/module-woocommerce`**.

## Directory overview

- `guides/`
  - [Module overview](guides/module-overview.md) — what this repo is, layout vs `integration-woocommerce`, quality gates, distribution zip
  - `clickup-workflow.md` — **repository / Cursor only** (ClickUp list, tag, `CU-` commits). **Not** included in the merchant zip built by [`scripts/build-plugin-zip.sh`](../scripts/build-plugin-zip.sh); use **[AGENTS.md](../AGENTS.md)** and **[`.cursor/rules/`](../.cursor/rules/)** for the same workflow.

## Quick links

- **[Module overview](guides/module-overview.md)** — architecture and file map
- **Root [README.md](../README.md)** — installation, connect, carrier meta mapping, API table, order panel, filters, i18n / branding
  - [Carrier meta mapping](../README.md#carrier-meta-mapping-woocommerce--octavawms) — map `courierName` / `courierID` / `delivery_type` order meta to OctavaWMS delivery service + pickup type

## Agent / contributor notes

Instructions for coding agents (commits, ClickUp, `composer check`) live in **[AGENTS.md](../AGENTS.md)** at the repo root. Cursor-specific rules live under **[`.cursor/rules/`](../.cursor/rules/)**. Optional human-readable ClickUp detail: [guides/clickup-workflow.md](guides/clickup-workflow.md) (git checkout only — omitted from distribution zip).

## Related documentation (sibling repos)

| Repo | Use when you need |
|------|-------------------|
| [integration-woocommerce](https://github.com/OctavaWMS/integration-woocommerce) | Panel routes, webhooks, Gearman order download, source settings, pricing in the app |
| [integration-shopify](https://github.com/OctavaWMS/integration-shopify) | Shopify-only features (not applicable to this plugin, but similar `docs/` index style) |
