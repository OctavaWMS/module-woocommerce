# OctavaWMS WooCommerce **module** documentation

WordPress + WooCommerce plugin package: **`octavawms/module-woocommerce`**.

## Directory overview

- `guides/`
  - [Module overview](guides/module-overview.md) — what this repo is, layout vs `integration-woocommerce`, quality gates, release
  - [ClickUp workflow](guides/clickup-workflow.md) — **Modules** list, tag **`module-woocommerce`**, commits, PR sync, timers

## Quick links

- **[Module overview](guides/module-overview.md)** — architecture and file map
- **[ClickUp workflow](guides/clickup-workflow.md)** — task list, tag, `CU-` commits, Solution field, MCP time-entry notes
- **Root [README.md](../README.md)** — installation, connect, API table, order panel, filters, i18n / branding

## Agent / contributor notes

Instructions for coding agents (commits, ClickUp, `composer check`) live in **[AGENTS.md](../AGENTS.md)** at the repo root. Cursor-specific rules live under **[`.cursor/rules/`](../.cursor/rules/)**.

## Related documentation (sibling repos)

| Repo | Use when you need |
|------|-------------------|
| [integration-woocommerce](https://github.com/OctavaWMS/integration-woocommerce) | Panel routes, webhooks, Gearman order download, source settings, pricing in the app |
| [integration-shopify](https://github.com/OctavaWMS/integration-shopify) | Shopify-only features (not applicable to this plugin, but similar `docs/` index style) |
