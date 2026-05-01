# ClickUp workflow (OctavaWMS WooCommerce **module**)

This repo is the **WordPress + WooCommerce plugin** (`octavawms/module-woocommerce`). Tasks for it live in ClickUp under the **Modules** list with tag **`module-woocommerce`**.

Cursor automation mirrors this file in [`.cursor/rules/clickup-task-and-timers.mdc`](../../.cursor/rules/clickup-task-and-timers.mdc) and [`.cursor/rules/clickup-pull-task-sequence.mdc`](../../.cursor/rules/clickup-pull-task-sequence.mdc).

## Target list

| Field | Value |
|--------|--------|
| Space | **OctavaWMS** |
| List | **Modules** |
| `list_id` | `901217643164` |

## Required tag

- **`module-woocommerce`** — include when creating or tagging tasks for this repository.

## Task creation

1. If no linked task exists, create one in the **Modules** list (`901217643164`).
2. Always add tag **`module-woocommerce`**.
3. After creation, set status to **in development** when that status exists on the list.

When the human asks to sync with ClickUp or “put in review + log time”, do not block on a missing task id if creating a task is appropriate; create the task and proceed.

## Git commits

- Prefix the subject with **`CU-<ClickUpTaskId>`** and a space, then an imperative summary.  
  Example: `CU-869c9qwdy Fix label download when HPOS order id differs`
- If the task id is unknown, **ask the human** before committing.

## Pull task → implement → PR

End-to-end behaviour (fetch task, timers, `composer check`, branch naming, PR body with ClickUp link, merge on approval) matches the other OctavaWMS integration repos. See [`.cursor/rules/clickup-pull-task-sequence.mdc`](../../.cursor/rules/clickup-pull-task-sequence.mdc).

High level:

1. Fetch the task (`clickup_get_task`); read name, description, comments, tags (ensure **`module-woocommerce`** where applicable).
2. **Solution** custom field id (for post-implementation summary): `df3568d4-5a08-40fc-9ee3-f9676105e94f`.
3. Branch names like `CU-<id>-short-slug`; commits and PR title use **`CU-<id>`**.
4. After code changes, run **`composer check`** (see [module overview](module-overview.md#quality--tests)).

## Time tracking

- Start/stop timers on the linked task when planning and implementation phases start and end.

### Manual time entry (MCP) — minimal payload

Prefer a **minimal** `clickup_add_time_entry` body:

- **Include:** `task_id`, `start`, **`end_time`** as strings `YYYY-MM-DD HH:MM`.
- **Or:** `task_id`, `start`, `duration` (e.g. `45m`).
- **Omit** unless explicitly required: `description`, `tags`, `billable`.

If the API rejects time entry, skip logging and put the intended duration in a **task comment** so it is not lost.

## Post-implementation sync

1. Stop any running timer.
2. Move task to **in review** when ready, if that status exists.
3. Set **Solution**:  
   `custom_fields: [{"id": "df3568d4-5a08-40fc-9ee3-f9676105e94f", "value": "<1–2 sentences>"}]`
4. Add a task comment with branch name, PR link, and any merge note when closing.

## Task URL shape

`https://app.clickup.com/t/<id>` — the id matches the segment after `CU-` in commits (e.g. `CU-869d25yab` → `869d25yab`).
