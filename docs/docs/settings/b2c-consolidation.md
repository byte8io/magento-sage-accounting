---
sidebar_position: 7
title: B2C consolidation
description: Collapse low-ticket guest orders onto a single fallback Sage contact instead of one row per customer. The headline B2C feature for high-volume stores.
---

# B2C consolidation

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **B2C Consolidation** card.

The headline B2C feature. Without consolidation, every guest order on your Magento store creates a new Sage contact — a high-volume B2C store can fill Sage's contact list with thousands of one-time-buyer rows that the accountant never wants to see.

When enabled, low-ticket guest / one-time orders route to a **single fallback Sage contact** ("Magento Web Sales"). High-value orders, B2B (company name present), and repeat customers still get individual contacts.

Off by default — opt-in.

## When to enable

Recommended **on** if:

- You're a B2C store with > 100 orders / month from guest customers.
- Your accountant has complained about the size of the Sage contact list.
- You don't need per-customer reporting in Sage (you do customer reporting in Magento or a CDP).

Recommended **off** if:

- B2B-heavy / low-volume, where every order is a known recurring customer.
- Your accountant audits sales by customer in Sage (consolidation breaks this).

## The routing rule

In priority order, **first match wins**:

1. **`enabled = false`** → individual contact (the no-consolidation path).
2. **B2B (company name present)** + `always_individual_for_b2b = true` → individual contact. Recommended on; protects B2B customers from accidental consolidation.
3. **Repeat customer** (the chassis already has an `entity_xref` row for this Magento customer id) → individual contact. Once a customer has a Sage contact, all their future orders route there too — even if they fall below `min_total_for_individual` later.
4. **`grand_total >= min_total_for_individual`** → individual contact. The "high-value first-time buyer" exception.
5. **Otherwise** → fallback contact. The default for low-ticket first-time guests.

## The five fields

### `enabled`

Master toggle. `false` → no consolidation, classic per-customer routing.

### `fallback_contact_name`

Default: `"Magento Web Sales"`.

The `displayed_as` name of the fallback Sage contact. Whatever you pick shows in Sage's contact list, on every consolidated invoice's contact field, and in your Sage AR aging report.

Required when `enabled = true`. Field error if empty.

### `fallback_contact_email`

Default: `"sales@your-shop.example.com"`.

Sage requires every contact to have an email. The chassis uses this address on the fallback contact (it's never the actual buyer's email — buyers always get the per-invoice address overrides on the SageInvoice).

Required when `enabled = true`. Validated as a well-formed email.

### `fallback_contact_reference`

Default: `"WEBSALES"`.

The Sage `reference` field on the fallback contact (analogous to a customer code). Sage caps this at 10 chars. The chassis adds a per-currency suffix on the contact-currency variants (`WEBSALESE` for EUR, `WEBSALESU` for USD), so leave 1 char of headroom for the suffix on multi-currency stores.

Required when `enabled = true`. Field error if > 10 chars.

### `min_total_for_individual`

Default: `0` (off — every consolidatable order goes to fallback).

The grand_total threshold above which a first-time guest still gets their own Sage contact. Useful for high-value first-time buyers — you might want to track a £5,000 first-time order individually even on a B2C-consolidating store.

Set to `0` to disable. Set to whatever value (e.g. `100` for £100) above which a guest order is "interesting enough" to track individually — consolidate only the small change.

### `always_individual_for_b2b`

Default: `true`. Strongly recommended on.

When `true`, any order where the customer has a non-empty company name routes individually. B2B customers don't get consolidated into the catch-all even if their first order is small.

When `false`, B2B is treated like B2C — purely by `min_total_for_individual` and repeat-customer status.

## What the fallback Sage contact looks like

On first sync that needs it, the chassis lazily creates a single Sage contact with the configured name + email + reference. Cached under `entity_xref(contact_fallback, magento:b2c_fallback)`. Subsequent qualifying invoices route to the same contact.

**One fallback contact per binding.** Per-currency variants (the chassis creates a `WEBSALES` for GBP, `WEBSALESE` for EUR, etc) are separate Sage contacts because Sage locks contacts to one currency.

**Address on consolidated invoices** — the fallback contact has no address (no real buyer). The actual buyer's address ships per-invoice via Sage's `main_address` + `delivery_address` overrides on the `SageInvoice` itself, so dispatch labels stay correct. The accountant sees the consolidated contact in the AR list but can drill into any individual invoice for the real ship-to / bill-to.

## Credit notes route to the same contact

Critical Sage constraint: credit notes can only allocate against invoices that share the same contact. The chassis routes credit memos via the same B2C consolidation logic on `(customer, grand_total)` from the credit memo itself — so a credit note for a consolidated invoice lands on the same fallback contact as the original. Allocations work cleanly.

## Switching consolidation on/off mid-flight

Safe in either direction:

- **Off → On:** existing per-customer Sage contacts stay; new qualifying orders route to fallback. Repeat customers (already have an xref row) stay individual via rule 3.
- **On → Off:** existing fallback contact stays in Sage with its accumulated history; new orders route per-customer.

No data migration; no cleanup needed in Sage. The behaviour switch is forward-only — historical rows stay where they were.

## Per-currency note

If you're running multi-currency (see [Multi-currency](/docs/settings/multi-currency)), the consolidation fallback creates **one Sage contact per currency** (`WEBSALES` GBP, `WEBSALESE` EUR, `WEBSALESU` USD). Same routing logic, applied per-currency.

The "repeat customer" rule (rule 3) is also per-currency — a guest with GBP order history is still treated as first-time on their first EUR invoice. If you want broader semantics, lower `min_total_for_individual` or set `always_individual_for_b2b = true`.
