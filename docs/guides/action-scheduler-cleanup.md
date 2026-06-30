# Action Scheduler cleanup for OctavaWMS imports

Use this runbook when WooCommerce Action Scheduler has a large backlog of pending `octavawms_import_order` actions.

## Preconditions

- Take a database backup first.
- Temporarily disable **Async import** and **Auto-sync order updates** under **WooCommerce -> Settings -> Integrations -> OctavaWMS Connector** so the queue stops growing while cleanup runs.
- Use WP-CLI on the affected store and check the local command syntax with `wp action-scheduler --help` and `wp action-scheduler action --help`; Action Scheduler CLI options vary by version.

## Export and count

Export pending OctavaWMS import actions before deleting anything:

```bash
wp action-scheduler action list --hook=octavawms_import_order --group=octavawms --status=pending --format=json > octavawms-import-pending.json
```

If the local Action Scheduler version does not support those filters on `action list`, use the available `--help` output to filter by hook, group, and pending status, or export all pending actions and filter offline.

Keep the export until orders have been reconciled. The action args contain the WooCommerce order id, which can be used to re-import each affected order once after cleanup.

## Cancel pending OctavaWMS import actions

Cancel or delete only actions matching all of these fields:

- hook: `octavawms_import_order`
- group: `octavawms`
- status: `pending`

Do not cancel running actions. They may already be calling OctavaWMS.

For very large queues, process ids from the export in batches instead of using a single shell command with millions of ids. Confirm after each batch that the count is decreasing and that WooCommerce/Action Scheduler tables are not locking for too long.

## Reconcile and resume

After pending OctavaWMS import actions are removed:

- deploy the fixed plugin version;
- re-import at most one job per distinct WooCommerce order id from the export, or reconcile recent orders manually from WooCommerce and OctavaWMS if the export is too large to process;
- run the normal Action Scheduler queue so WooCommerce Analytics/Statistics jobs can catch up;
- re-enable automatic sync settings once the queue is healthy.
