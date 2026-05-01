# Agent instructions (OctavaWMS / WooCommerce **module**)

For the **WordPress + WooCommerce plugin** in this repository. Cursor-specific automation lives under [`.cursor/rules/`](.cursor/rules/).

## Project

- **Package:** `octavawms/module-woocommerce` — PHP **8.1+**, WordPress plugin (PSR-4 `OctavaWMS\WooCommerce\`).
- **Quality gate:** after substantive PHP or config changes, run **`composer check`** (`php-lint` + PHPUnit per `composer.json`) and fix reported issues.

## Git commits

- Prefix every commit subject with **`CU-<ClickUpTaskId>`** and a space, then an imperative summary.  
  Example: `CU-869c9qwdy Fix connect log redaction for nested JSON`
- If the task id is unknown, **ask the human** before committing.

## ClickUp (when the workflow applies)

- **List:** OctavaWMS → **Modules** (`list_id: 901217643164`).
- **Tag:** **`module-woocommerce`** on tasks for this repo.
- **Task URL:** `https://app.clickup.com/t/<id>` (id matches the part after `CU-` in commits).
- **Pull task → code → PR:** see [`.cursor/rules/clickup-pull-task-sequence.mdc`](.cursor/rules/clickup-pull-task-sequence.mdc). Human-readable detail: [`docs/guides/clickup-workflow.md`](docs/guides/clickup-workflow.md).

## Docs worth reading

| Topic | Path |
|--------|------|
| Docs index | [`docs/README.md`](docs/README.md) |
| Module vs cloud integration, file map | [`docs/guides/module-overview.md`](docs/guides/module-overview.md) |
| ClickUp list, tag, timers, Solution field | [`docs/guides/clickup-workflow.md`](docs/guides/clickup-workflow.md) |
| Install, API reference, UX | root [`README.md`](README.md) |
