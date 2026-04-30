---
title: What syncs
description: Entity-by-entity matrix — which Magento events land in which Sage entities.
---

# What syncs

The full Magento → Sage entity matrix. Direction is **always** `M → S` in v1 (Magento source of truth, Sage ledger of record).

For per-plan feature gating (which entities are available on which tier), see the **[Plans & pricing page on byte8.io](https://byte8.io/products/sage-accounting#pricing)**.

## Entities

| Magento event | Sage entity | What's posted |
|---|---|---|
| **`invoice.created`** | `sales_invoice` (status: OPEN) | Outstanding AR invoice with full line items, addresses, currency, exchange rate, per-line discounts, dedicated shipping panel. |
| **`invoice.paid`** | `contact_payment` + `contact_allocation` | Auto-payment routed per [Payment-method map](/docs/settings/payment-methods); allocates against the matching Sage invoice via `entity_xref`. |
| **`creditmemo.created`** | `sales_credit_note` | Refund routed to the same contact as the original invoice (Sage requires this for clean allocation). Includes shipping refund line + original-invoice date for accountant linkage. |
| **`customer.upserted`** | `contact` (per-currency variant) | Magento customer → Sage contact, with per-currency dedup. Multi-currency stores get one Sage contact per transaction currency. |
| **`product.upserted`** (`sync_products = true`) | `product` / `service` / `stock_item` | Magento simple → Sage `product`; virtual / downloadable → `service`; managed-stock simples (with `sync_stock = true`) → `stock_item` with `/stock_movements` reconciliation. |

## What's NOT synced (intentionally, in v1)

- **Standalone payments without an invoice.** Magento has no API to attach an offline payment to an existing invoice; Sage requires every payment to allocate against an existing AR row. The chassis intentionally doesn't ship a `payment.captured` flow — accountants reconcile offline payments manually in Sage. (See the "Option B" notes in `apps/ledger/__docs/PROGRESS.md` for the future-merchant case.)
- **Sage → Magento writeback.** Enterprise on request — needs Sage webhook surface + Magento write endpoints + conflict-resolution policy.
- **Inventory writes from Sage.** Stock-item sync is one-way M → S only. Stock changes made directly in Sage drift; the next Magento save reconciles via the snapshot + `STOCKTAKE` movement.
- **Composite product types** (`configurable`, `bundle`, `grouped`). Skipped at translate time with `reason: PRODUCT_TYPE_NOT_SUPPORTED` — Sage doesn't model variants the way Magento does. Their child simples sync individually.
- **Tier pricing + special pricing.** Only `price` is transmitted on the catalog upsert. Future config knobs (`price_strategy: base | special | lowest`, `tier_pricing_enabled`) are deferred to a real merchant ask.

## Idempotency keys

Every event carries a stable idempotency key:

| Event | Key shape |
|---|---|
| `invoice.created` | `invoice.created:{entity_id}` |
| `invoice.paid` | `invoice.paid:{entity_id}` |
| `creditmemo.created` | `creditmemo.created:{entity_id}` |
| `customer.upserted` | `customer.upserted:{entity_id}` |
| `product.upserted` | `product.upserted:{entity_id}` |

The chassis dedupes on these keys so observer re-fires, duplicate Magento saves, and replays are safe — never produces duplicate Sage entities.

The chassis also dedupes downstream via `entity_xref` (Magento entity_id ↔ Sage entity uuid). This is the second line of defence: if a chassis-side bug ever caused a duplicate POST, the `entity_xref` lookup catches it and routes to the existing Sage entity.

## Sync filters in priority order

What gets to Sage is gated by the binding's sync policy:

1. **`sync_unpaid_invoices: false`** filters out unpaid invoices entirely.
2. **`sync_zero_value_invoices: false`** filters out £0 invoices.
3. **`sync_since`** filters out everything before the cutover date.
4. **`website_filter`** + **`store_filter`** restrict to specific Magento sites.
5. **`sync_products: false`** (default) filters out the entire `product.upserted` event stream.
6. **`sync_stock: false`** (default) routes managed-stock simples to `/products` (not `/stock_items`).

Skips are auditable in the dashboard sync history with stable reason codes.

## Plan-gated features

Some entities (credit notes, payments, products, stock-item sync, estimates, multi-currency, multi-store) require higher-tier plans. The full per-plan feature matrix lives on the **[Plans & pricing page](https://byte8.io/products/sage-accounting#pricing)**.

If you try to enable a feature your plan doesn't include (e.g. flipping on `sync_products` outside its tier), the chassis blocks it server-side with a clear `tier_limit_exceeded` validation error on the policy save.
