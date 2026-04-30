# Byte8 Sage Accounting

Magento 2 connector for **Sage Business Cloud Accounting**. Syncs orders, invoices, credit memos, customers, and products from Magento into Sage in near real time, with full sync-status visibility from the Magento admin.

This module is the per-provider thin client. The heavy lifting (OAuth token custody, Sage API calls, retry, audit) lives in the Byte8 Ledger SaaS — see [Architecture](#architecture) below.

## Features

- **One-click Connect** — pairing-code flow (no OAuth callback wrangling). Generate a code in Magento admin, paste into ledger.byte8.io, done.
- **Outbound sync observers** — `invoice.created` / `invoice.paid` / `creditmemo.created` / `customer.upserted` / `product.upserted` events fire on every save and queue durably (no inline HTTP — checkout stays snappy).
- **Sage Status chips** in the admin — sortable + filterable column on Sales → Invoices and Sales → Credit Memos grids; "Sage Accounting" info block on every invoice / credit-memo detail page. Operators see what synced without leaving Magento.
- **Multi-currency aware** — orders raised against EUR / USD storefronts post to Sage with the correct `currency_id` + `exchange_rate` and per-currency contact dedup. Cross-border (non-GB customer) invoices route to GB_ZERO with EU goods/services routing.
- **Stock-item snapshot sync** — opt-in `sync_stock` mode routes managed-stock simples onto Sage's STOCK_ITEM family with stock-movement reconciliation.
- **B2C consolidation** — opt-in mode collapses low-ticket guest orders onto a single fallback Sage contact instead of one row per customer.
- **Idempotent everything** — every event carries a stable idempotency key (`invoice.created:{entity_id}` etc); ledger dedupes via the shared `entity_xref` table. Observer re-fires, duplicate saves, replay all safe.
- **Operator-visible failures** — failed deliveries surface as a banner on the admin config page (not a silent 24h drop).

## Connect flow

1. Stores → Configuration → **Byte8 → Sage Accounting** → click **Generate pairing code** (30-min TTL).
2. Click **Open Byte8 Ledger** to land on `ledger.byte8.io`, sign in, paste the code + your Magento URL.
3. Ledger calls back into Magento (`POST /V1/byte8/setup/pair`) with the api_key. The connection status block flips to "Connected" — you're done.

After connecting, Sales-side observers fire automatically; no per-merchant configuration required for the happy path. Per-tenant sync policy (default ledger account, payment-method map, etc.) is configured from the ledger dashboard, not Magento admin.

## What syncs

| Magento event | Sage entity | Notes |
| --- | --- | --- |
| `invoice.created` | `sales_invoices` (OPEN) | Posts as outstanding AR; B2B / net-terms / COD flows correctly visible in Sage |
| `invoice.paid` | `contact_payments` + `contact_allocations` | Auto-allocates payment to the matching invoice; mapped per `payment_method_map` policy |
| `creditmemo.created` | `sales_credit_notes` | Reuses original-invoice contact (Sage requires matching contacts for allocation) |
| `customer.upserted` | `contacts` | Per-currency contact (`M5E`/`M5U`/...) for cross-currency merchants |
| `product.upserted` | `products` / `services` / `stock_items` | Routed by Magento product type; stock-item path is opt-in |

What's intentionally NOT synced today: standalone payments without an invoice (Magento has no API for offline-payment attach — accountant reconciles in Sage), Sage→Magento writeback (Enterprise on request).

## Sync visibility in Magento admin

- **Sales → Invoices** grid — "Sage Status" column with chips: `✓ Synced` / `⏳ Pending` / `⏸ Skipped` / `✗ Failed` / `—`. Sortable + filterable. Hover for Sage entity reference, skip-reason, or error-code.
- **Sales → Credit Memos** grid — same column.
- **Invoice / Credit Memo detail pages** — "Sage Accounting" info block beside "Order Information" with status, Sage reference, last sync timestamp, skip/error context.

Pending chips appear immediately when an observer fires (write-through to `byte8_entity_sync_state` at enqueue time); terminal status (synced / skipped / failed) lands within 60s once the ledger worker picks up the event.

For full per-event audit trail (with retry, error message, payload diff), use the ledger dashboard at `ledger.byte8.io`.

## Architecture

```
┌─────────────────────┐     ┌──────────────────────┐     ┌────────────────────┐
│  Magento 2          │◄───►│   Byte8 Ledger       │◄───►│  Sage Business     │
│  (this module +     │ JWT │   (SaaS chassis)     │OAuth│  Cloud Accounting  │
│   byte8/module-     │     │                      │     │                    │
│   client)           │     │   apps/ledger        │     │                    │
└─────────────────────┘     └──────────────────────┘     └────────────────────┘
```

This module is **thin by design**. It owns:

- Magento-side observers (queue events on save)
- Pairing-code flow + admin config blocks
- Inbound REST endpoints under `/V1/byte8/*` (canonical entity getters + sync-state callback)
- The `byte8_entity_sync_state` mirror table and the admin UI surfaces that read from it

It does **NOT** own OAuth token custody, Sage API calls, retry logic, dead-letter queues, or per-tenant rate limiting — those all live in the Byte8 Ledger SaaS. This split means the module can stay tiny (no PHP-side OAuth dependencies, no `vendor/` bloat) and a single ledger instance services every connected merchant.

## Requirements

- PHP 8.1+ (8.4 supported)
- Magento Open Source / Adobe Commerce 2.4.4+
- MySQL 8.0+ / MariaDB 10.6+
- A Byte8 Ledger account (pairing code issued from `ledger.byte8.io`)
- Outbound HTTPS to `ledger.byte8.io` (port 443). Provider-side network access (`api.accounting.sage.com`) happens from the ledger SaaS — Magento never talks to Sage directly.

## Installation

```bash
composer require byte8/magento-sage-accounting
bin/magento module:enable Byte8_Core Byte8_Client Byte8_SageAccounting
bin/magento setup:upgrade
bin/magento cache:flush
```

The `byte8/magento-sage-accounting` metapackage pulls in `byte8/module-core` + `byte8/module-client` + this module.

## Console commands

```bash
bin/magento byte8:sage:invoice:sync <invoice_id>
```

Operator-driven sync trigger for a single invoice. Used during dev / triage when you want to bypass the cron drain. Production sync goes through the standard observer + cron path.

```bash
bin/magento byte8:sage:outbox:inspect
bin/magento byte8:sage:outbox:requeue <entity_id>
bin/magento byte8:sage:outbox:cleanup [--days=30]
```

Outbox triage commands (live in `byte8/module-client`). See `module-client/SECURITY.md` for the full operator runbook.

## Configuration

Stores → Configuration → **Byte8** → **Sage Accounting**:

- **Connection status** — paired / not paired, dead-letter banner on failed deliveries.
- **Pairing code** — generate / regenerate. 30-min TTL.
- **Open Byte8 Ledger** — quick redirect to the dashboard.
- **Disconnect** — revokes the binding both sides.

All sync policy (default tax rate, default ledger account, default bank account, payment method map, B2C consolidation, multi-currency knobs, sync filters, ...) is configured from the ledger dashboard, not Magento admin. See [byte8.io docs](https://byte8.io/docs/sage-accounting) for the full list.

## License

[MIT](LICENSE.txt)

## Support

Byte8 Ltd — support@byte8.io
