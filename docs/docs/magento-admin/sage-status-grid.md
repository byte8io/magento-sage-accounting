---
sidebar_position: 1
title: Sage Status grid columns
description: The "Sage Status" column on Sales → Invoices and Sales → Credit Memos. Chips, hover tooltips, sortability, and what each status means.
---

# Sage Status grid columns

A sortable + filterable **Sage Status** column on:

- **Sales → Invoices** (`/admin/sales/invoice/index`)
- **Sales → Credit Memos** (`/admin/sales/creditmemo/index`)

The chip tells you, at a glance, whether each row reached Sage — without leaving the Magento admin you already live in.

## The five chip variants

| Chip | Meaning |
|---|---|
| <span className="sage-chip sage-chip--synced">✓ Synced</span> | Reached Sage successfully. Hover for the Sage entity id. |
| <span className="sage-chip sage-chip--pending">⏳ Pending</span> | In flight — outbox row enqueued, not yet drained or not yet round-tripped. Typically clears within 60 s. |
| <span className="sage-chip sage-chip--skipped">⏸ Skipped</span> | Filtered out by sync policy. Hover for the skip-reason code (e.g. `payment_method_not_mapped`, `outside_sync_since`, `zero_value_invoice`). |
| <span className="sage-chip sage-chip--failed">✗ Failed</span> | Hard failure in the chassis — auth issue, validation error, Sage 4xx. Hover for the error-code class (e.g. `provider`, `validation`, `http`). Investigate via the ledger dashboard's sync history. |
| <span className="sage-chip sage-chip--none">—</span> | No sync attempted. Either the row pre-dates the install, the binding wasn't connected when this row was created, or the row falls outside the sync filters in a way that didn't even produce a `skipped_by_policy` row. |

## Sorting + filtering

Click the **Sage Status** column header to sort. Use the column's filter dropdown to narrow the grid to one chip variant — useful for "show me everything that failed in the last 24h."

## Why pending appears immediately

The chip flips to `⏳ Pending` the **moment** the merchant clicks Submit Invoice — not 60 s later when the cron drain finally picks it up. This is the PR7 write-through behaviour:

- `ByteClient::enqueueEvent` writes the outbox row AND a `pending` row in `byte8_entity_sync_state` synchronously.
- The grid LEFT JOINs against `byte8_entity_sync_state` per row → chip renders pending immediately.

When the chassis terminal callback lands (typically 5-60 s later), the same row is UPSERTed with the terminal status (`synced` / `skipped` / `failed`). Refresh the grid to see the chip update.

## Hover tooltip

The chip's `title` attribute carries useful context per row:

- **Synced** → Sage entity id (Sage's UUID — opaque to humans but useful in support tickets)
- **Skipped** → skip-reason code (`payment_method_not_mapped`, `outside_sync_since`, etc — see [Sync behaviour](/docs/settings/sync-behavior) for the full list)
- **Failed** → error-code class (one of: `auth`, `not_found`, `validation`, `rate_limited`, `provider`, `http`, `database`, `serde`, `internal`)

For the human-readable Sage reference (`SI-27`, `CN-5`), see the next page — the [detail-page info block](/docs/magento-admin/sage-status-detail) shows it explicitly.

## What if I see `—` on an invoice that should have synced?

Three common causes:

1. **The invoice pre-dates the install.** The chassis only writes mirror rows for terminal `mark_*` calls *after* PR7 deployed. Historical invoices (synced before PR7) have no mirror row. Either retry from the ledger dashboard (re-fires the callback and populates the row), or backfill via SQL — see [Troubleshooting → Backfill pre-PR7 rows](/docs/troubleshooting#backfill-pre-pr7-rows).
2. **Binding wasn't connected** when the invoice was raised. Check the chassis dashboard — was the binding `connected` at the time? Reconnect + retry.
3. **Cron isn't running.** Verify `bin/magento cron:run --group=default` works manually. If your install relies on system cron, check it's calling Magento's cron entry point on the minute.

## No chip on Sales → Orders

Intentional. Orders aren't synced directly — we sync invoices, of which an order can have 0..N. A row-level chip on the Orders grid would either need a synthetic rollup (obscuring multi-invoice partial-sync state) or arbitrarily pick one invoice. Operators drill into the Invoices tab from the order to see per-invoice status.

This may change in v1.1 if a design partner asks for an Orders-grid roll-up — let us know if you'd find it useful.

## No chip on Customers grid

Same reasoning. Per-currency contact dedup (see [Multi-currency](/docs/settings/multi-currency)) means one Magento customer maps to N Sage contacts. A single row-level chip would either need an aggregate (which obscures per-currency state) or pick one currency arbitrarily. Customer detail-page block with all per-currency references is the planned v1.1+ surface.
