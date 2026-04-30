---
sidebar_position: 2
title: Default mappings
description: Default tax rate, ledger account, bank account, purchase-ledger account. The fallback values used when a Magento line / invoice doesn't carry enough information to route itself.
---

# Default mappings

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Default mappings** card.

Sage requires every invoice line to carry a tax rate, a ledger account, and (for payments) a bank account. Magento doesn't always carry that information directly — defaults fill the gap.

All four fields are **dropdowns populated from the chassis's reference cache** (filtered to the appropriate Sage `visible_in` bucket). Type-aheads work; typing a partial name filters the list.

## `default_tax_rate_id`

**Bucket:** `tax_rates` (Sage `/tax_rates`).

The tax rate applied to invoice / credit-memo lines that don't carry a `tax_class` or whose tax class isn't in the [Tax-rate map](/docs/settings/tax-rates).

Typical UK choices:

- `GB_STANDARD` — 20% VAT (most B2C goods)
- `GB_LOWER` — 5% VAT (children's car seats, electricity, …)
- `GB_ZERO` — 0% (children's clothes, exports, books)
- `GB_EXEMPT` — exempt (financial services, education)
- `GB_NO_TAX` — no tax (out-of-scope items)

Cross-border invoices (non-GB customer in a UK Sage business) auto-route to `GB_ZERO` regardless of this default — see [Multi-currency](/docs/settings/multi-currency) §29.

The validator rejects values not in the cached `tax_rates` list with a red-underline `defaultTaxRateId` field error before save. Typos surface form-time, not at sync-time as a 422 dead-letter.

## `default_ledger_account_id`

**Bucket:** `ledger_accounts?visible_in=sales` (Sage's documented filter).

The default sales account credited on invoice lines (e.g. `Sales — Products (4000)`, `Sales — Services (4010)`). Set this to the Sage account where most of your Magento sales should book.

The chassis filters the dropdown to only sales-visible accounts so you can't accidentally pick `Office equipment 0030` (an asset account). Pre-PR-7 we'd see this footgun on every install — it's no longer possible.

For finer routing (e.g. shipping → its own account, or per-tax-class line routing), see [Tax-rate map](/docs/settings/tax-rates) for the per-rate override + [Commercial knobs](/docs/settings/commercial) for the planned shipping/discount split.

## `default_bank_account_id`

**Bucket:** `bank_accounts` (Sage `/bank_accounts`).

The default Sage bank account used for `contact_payments` when `invoice.paid` fires for a payment method *not* mapped in the [Payment-method map](/docs/settings/payment-methods).

Optional. When unset:

- Payment methods explicitly mapped → payment routed to the mapped bank account.
- Payment methods unmapped + no default → invoice stays UNPAID in Sage; accountant reconciles manually when the cheque / bank transfer lands. **This is the documented B2B / net-terms behaviour** and on-purpose for offline payment methods (`checkmo`, `banktransfer`, `purchaseorder`).

When set:

- Unmapped methods route to this default account. Useful if you trust a single "catch-all" bank account to capture every online-card payment that didn't map specifically.

## `default_purchase_ledger_account_id`

**Bucket:** `ledger_accounts?visible_in=expenses`.

Required when `sync_products = true` (see [Sync behaviour](/docs/settings/sync-behavior)). Sage's `POST /products` validates this field even for sales-only products (Sage v3.1 quirk — we worked around but the spec is the spec).

Set to a Cost-of-Sales account (`5000` in the standard UK CoA) for resold goods. The product upsert won't dead-letter without this; the chassis pre-validates form-time.

## Reference cache freshness

The reference cache rebuilds:

- **At connect time** (post-OAuth handshake), once.
- **Auto on settings page load** if older than 24 hours — the dashboard fires `refreshReferenceData(bindingId)` once-per-mount with a useRef guard. You'll see "Refreshing reference data from Sage…" briefly in the actions bar.
- **Manually** via the **Refresh** button beside the freshness timestamp on the settings page. Use this when you've added a new Sage tax rate / ledger account / bank account in Sage and want it to appear in the dropdown immediately.

Freshness is shown as `Updated 14 minutes ago` (with absolute timestamp on hover). If something looks stale or missing, click Refresh.

## What if a default override is no longer valid?

If you delete a Sage tax rate / ledger account / bank account that the binding's policy still references (e.g. you mapped `default_tax_rate_id = "old-rate-uuid"` and then deleted that rate in Sage), the dropdown shows it as `(missing) old-rate-uuid` in red. Pick a replacement before saving — the validator blocks save with a `not_in_reference` field error otherwise.
