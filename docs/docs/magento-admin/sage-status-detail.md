---
sidebar_position: 2
title: Sage Accounting info block
description: The "Sage Accounting" admin info block on Invoice + Credit Memo detail pages — chip, Sage reference, last sync timestamp, skip / error context.
---

# Sage Accounting info block

Every **Invoice** and **Credit Memo** detail page gets a "Sage Accounting" info block sitting beside the standard "Order Information" / "Invoice Information" block.

What it shows:

- **Status** — the same chip as the grid column (Synced / Pending / Skipped / Failed / —)
- **Sage Reference** — the human-readable identifier (e.g. `SI-27` for invoices, `CN-5` for credit notes). v1.1+ — currently the chassis sends `null` here; the column will populate once the provider trait surfaces `displayed_as` from the Sage create response.
- **Sage Entity ID** — the Sage UUID. Useful for support tickets ("what's the Sage UUID of this thing?").
- **Skip Reason** (when status = Skipped) — the stable code from `sync_policy::reasons::*`, e.g. `payment_method_not_mapped`.
- **Error Code** (when status = Failed) — the chassis classifier output (`provider`, `auth`, `validation`, etc.).
- **Last Sync** — the timestamp of the most recent terminal write to this row, in UTC.

## Layout

The block lives in the `order_additional_info` reference container — Magento's standard slot for "extra metadata" on order / invoice / credit-memo views. Sits below "Invoice Information" by default; theme overrides may reposition it.

## When does it render?

On every Invoice and Credit Memo detail page where the chassis has at least one mirror row for that entity. If no row exists (`byte8_entity_sync_state` empty for that `entity_id`), the block still renders but shows the `—` chip and only the "Status" row.

## Linking out to the dashboard

Currently the block is **read-only display** — no link to the chassis sync history. If you need the full audit trail (every retry attempt, error message, payload diff), navigate manually to `ledger.byte8.io/dashboard/sync` and filter by `magento_entity_id = {your_id}`.

A direct deep-link from the info block to the dashboard's filtered view is a planned v1.1+ addition — let us know if you want it sooner.

## Why no info block on the Order detail page

Same reason as the grid: orders aren't synced directly; chip would need a multi-invoice rollup. The Order's "Invoices" tab shows each invoice's chip individually — drill in from there.

This may change in v1.1 if a design partner asks for an Order-level rollup info block (e.g. "1 of 2 invoices synced, 1 pending"). The chassis already has the data; just needs a UI surface.

## Why no block on the Customer detail page

Per-currency contact keying — one Magento customer maps to N Sage contacts. A single info block would either show an aggregate (lying about which contact is "the" contact) or pick one arbitrarily. The right surface is a per-currency block listing all variants — also v1.1+.

## Manual refresh

The info block is rendered server-side on each detail-page load. To see the latest sync state, just refresh the page. There's no client-side polling — if you want live updates, use the chassis dashboard's sync history instead.
