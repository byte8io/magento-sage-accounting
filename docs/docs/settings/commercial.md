---
sidebar_position: 5
title: Commercial knobs
description: Invoice number prefix, customer name priority, and other knobs that change how Sage entities are presented vs how Magento sees them.
---

# Commercial knobs

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Commercial Knobs** card.

Cosmetic + presentational knobs that control how Sage shows the entities the chassis posts. None affect what syncs — only how it's labelled in Sage's UI.

## `invoice_number_prefix`

**Default:** `null` (no prefix — Sage gets the Magento `increment_id` verbatim).

A short string (typically 3-6 chars) prepended to the Sage invoice + credit-note `reference`. Used by accountants to distinguish Magento-sourced documents from manually-created ones in the Sage UI.

Examples:
- `MAG-` → `MAG-100012345` in Sage (instead of just `100012345`)
- `WEB-` → `WEB-100012345`
- `M-` → `M-100012345`

The prefix applies to **both** invoice and credit-note references. Tolerates double-prefix on mid-flight rename — if you change the prefix from `MAG-` to `WEB-` and re-sync an invoice that already has `MAG-100012345` in Sage, the chassis won't double-prefix it.

## `customer_name_priority`

**Default:** `Company` (B2B-friendly).

When a Magento customer has both a company name and a person name, controls which becomes Sage's `displayed_as`:

- **`Company`** — B2B convention. Sage contact `displayed_as` = company name (e.g. `Acme Ltd`). Person name lives on `main_contact_person.first_name / last_name`.
- **`Person`** — B2C convention. Sage contact `displayed_as` = person name (e.g. `John Smith`). Company name lives on `main_address.company` if present.

Has **no effect** on customers with only one name field set — only branches when both are present.

Pick `Company` if your accountant invoices businesses primarily; pick `Person` if your B2C list dominates and the occasional company should display under the buyer's name.

## What's NOT here yet

The Commercial Knobs card is intentionally short — these are presentation knobs, not policy. Four knobs that **could** live here are deferred to v1.1+ until a real merchant asks:

- **Shipping account routing** — currently shipping flows into Sage's invoice-level `shipping_*` panel using Sage's internal shipping account. A `shipping_revenue_account_id` knob would let merchants route shipping to their own custom GL line. No design partner has asked.
- **Discount account routing** — line-level discounts already flow correctly via Sage's per-line `discount_amount`. Order-level discounts (Magento gift cards, store credit) currently fold into the grand total. A separate `discount_account_id` would itemise them. Waiting for the first store-credit merchant.
- **Sage `notes` template** — currently the chassis writes `Purchase Order: {po}` (when PO present) and `Credit for invoice {invoice_id} — {reason}` (on credit notes). A merchant-customisable template would let you change the wording.
- **Custom fields** — Sage has user-definable custom fields per entity. The chassis doesn't write to them today; would let merchants tag invoices with `magento_order_id` or similar for cross-system reconciliation.

If you need any of these, email `helo@byte8.io` with the use case — they're all small additions, but we want real-merchant signal before shipping.
