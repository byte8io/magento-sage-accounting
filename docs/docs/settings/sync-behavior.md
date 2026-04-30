---
sidebar_position: 1
title: Sync behaviour
description: Toggles on the Sync Behaviour card — sync_unpaid_invoices, sync_zero_value_invoices, sync_since, website_filter, store_filter, sync_products, sync_stock. What each does, when to flip them.
---

# Sync behaviour

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Sync Behaviour** card.

Top-of-page knobs that control which Magento events even reach the provider. Anything filtered here never makes it to Sage; the worker logs a `skipped_by_policy` row in the audit trail with the reason code so the dashboard can group skips per cause.

All knobs default to permissive (sync everything) — flip them on only when you have a specific need.

## `sync_unpaid_invoices`

**Default:** `true` (sync invoices regardless of paid state).

When `true`: every invoice — paid or unpaid — posts to Sage as outstanding AR on creation. The separate `invoice.paid` event later attaches a `contact_payment` + allocation when Magento marks it paid.

When `false`: only invoices that are already PAID at creation time sync. Useful if your accountant only wants Sage to reflect cleared sales (no AR aging), but most B2B merchants want the unpaid-invoice flow because that's what carrying receivables looks like.

## `sync_zero_value_invoices`

**Default:** `true`.

Magento occasionally creates £0 invoices for fully-discounted orders, free samples, gift-card-only purchases, or stock-adjustment scenarios. When `false`, these are skipped (`reason: zero_value_invoice`) — useful for keeping the Sage AR ledger clean of "noise" lines.

When `true`, they post normally. Sage accepts £0 invoices.

## `sync_since`

**Default:** `null` (sync everything regardless of date).

Set to an ISO date (`2026-01-01T00:00:00Z`) to skip every invoice / credit memo with `created_at < sync_since`. Used for cutover migrations: "we did the manual reconciliation through 31 Dec 2025; everything from 1 Jan 2026 onwards comes through the connector."

Skipped rows get `reason: outside_sync_since`. They stay visible in the dashboard sync history filtered to that reason code so you can audit what was excluded.

## `website_filter`

**Default:** `[]` (no filter — all websites sync).

A list of Magento `website_id` values. When non-empty, only events from those websites sync. AND-combined with `store_filter`.

Use case: multi-website Magento install where one website is the actual store and the other is a staging / B2B portal you don't want flowing into Sage. Rather than disconnecting the connector, set `website_filter: [1]` to scope it to website_id 1 only.

Empty array = no filter = everything syncs. Don't confuse with `null` (which would also mean no filter, but the canonical form is empty array).

## `store_filter`

**Default:** `[]`.

Same shape as `website_filter` but for `store_id`. Lets you sync some stores within a website but not others (e.g. multi-language storefronts where you only want one language's orders flowing to Sage).

## `sync_products`

**Default:** `false` (opt-in).

Master switch for product sync. When `true`, the `product.upserted` observer fires on `catalog_product_save_after` and posts to Sage as `/products` (simple) or `/services` (virtual / downloadable). Composite types (configurable / bundle / grouped) are skipped — Sage doesn't model variants the way Magento does.

When you flip this on, the chassis also requires a `default_purchase_ledger_account_id` to be set in [Default mappings](/docs/settings/default-mappings) — Sage's product POST validates the field even on sales-only products (a Sage v3.1 quirk we worked around — see [Troubleshooting](/docs/troubleshooting)).

Pricing scope: only `price` syncs (no `special_price`, no tier pricing). Pricing follow-ups are deferred until a merchant asks.

## `sync_stock`

**Default:** `false` (opt-in; available on higher tiers — see [Plans & pricing](https://byte8.io/products/sage-accounting#pricing)).

When `true` (and `sync_products = true`), `simple` Magento products with `manage_stock=true` route to Sage's `STOCK_ITEM` family instead of `PRODUCT`. The worker also posts a `/stock_movements` reconciliation row when Magento's stock_qty diverges from Sage's `quantity_in_stock` — snapshot model, Magento as source of truth, Sage told to match.

Untracked simples (no `manage_stock`) and virtual / downloadable products are unaffected.

## How a "skipped by policy" event looks

Every skip is auditable. On the Magento side you'll see:

- The Sage Status grid chip stays at `⏸ Skipped` (amber).
- The detail-page "Sage Accounting" info block shows the skip reason.

On the dashboard side (`ledger.byte8.io/dashboard/sync`), the run row has `status: skipped_by_policy` and the reason code in `error_code` (e.g. `payment_method_not_mapped`, `outside_sync_since`, `zero_value_invoice`). Filter the page by status `skipped_by_policy` to audit what your filters are dropping.

## Saving changes

Click **Save** at the top-right of the settings page. Changes apply to the next event you trigger; in-flight events use the policy snapshot they captured at enqueue. **Revert** discards unsaved changes back to the last persisted policy.
