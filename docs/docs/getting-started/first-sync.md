---
sidebar_position: 3
title: Your first sync
description: Walk through what happens — observer side, queue side, Sage side — when you raise your first invoice after pairing. Useful for verifying the install end-to-end.
---

# Your first sync

The 60-second [Quick start](/docs/getting-started/quick-start) gets you paired. This page walks through what *actually happens* when you raise your first invoice — useful both for verifying the install end-to-end and for understanding the trace if something doesn't sync.

## Step 1 — Raise an invoice in Magento

In **Sales → Orders**, pick an order, **Invoice → Submit Invoice**. The Magento `invoice_save_after` event fires.

`InvoiceCreatedObserver` (`Byte8\SageAccounting\Observer\InvoiceCreatedObserver`) catches it on its first save (filters subsequent state-flip saves), confirms the `Byte8_SageAccounting` module is connected (`SageConfig::isConnected()`), then enqueues:

```php
$this->byteClient->enqueueEvent('invoice.created', [
    'magento_entity_id' => $entityId,
    'website_id'        => $this->resolveWebsiteId($invoice),
    'store_id'          => (int) $invoice->getStoreId(),
    'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
    'payload'           => ['increment_id' => $invoice->getIncrementId()],
], 'invoice.created:' . $entityId, SageConfigInterface::PROVIDER_KEY);
```

Two things happen synchronously inside `enqueueEvent`:

1. A row is inserted into `byte8_event_outbox` with `status = 'pending'`.
2. Because `$providerForMirror` is set (PR7 write-through), a row is also UPSERTed into `byte8_entity_sync_state` with `sync_status = 'pending'`.

The merchant's invoice-save click returns immediately. No HTTP, no Sage round-trip in the save transaction.

## Step 2 — Check the Magento admin grid

Navigate to **Sales → Invoices**. The new invoice's row shows a `⏳ Pending` chip in the Sage Status column. That chip came from the write-through write in Step 1 — it doesn't wait for the cron drain or the chassis callback.

This is the core PR7 UX: the chip appears the moment you click Submit Invoice, not 60 seconds later.

## Step 3 — Cron drains the outbox

Within 60 seconds the `byte8_outbox_drain` cron picks up the `pending` outbox row, signs a JWT, and POSTs to:

```
POST https://ledger.byte8.io/webhooks/magento/<your-tenant-id>/invoice.created
Authorization: Bearer <signed-JWT>
Idempotency-Key: invoice.created:42
{ "magento_entity_id": 42, "website_id": 1, ... }
```

The chassis verifies the JWT (HKDF subkey from your shared `api_key`), inserts a `sync_runs` row (status `queued`), publishes the job to its Redis queue, and returns `202 Accepted` with the new `sync_run_id`. Magento marks the outbox row `succeeded`.

## Step 4 — The worker fetches your canonical invoice

The chassis worker pops the job and calls back into your Magento:

```
GET https://your-shop.example.com/rest/V1/byte8/invoice/42
Authorization: Bearer <chassis-signed-JWT>
```

The thin module's `InvoiceRepository::get()` returns the canonical Magento invoice — snake_case, with line items, addresses, payment method, currency, base-to-order rate. The chassis then checks the binding's sync policy (e.g. `sync_unpaid_invoices`, `website_filter`, etc), and if it passes, calls `SageProvider::post_invoice(...)`.

## Step 5 — Sage POST

The provider:

1. Resolves or creates the Sage `contact` for this customer (per-currency keying — see [Multi-currency](/docs/settings/multi-currency)).
2. Translates the canonical invoice into Sage's `sales_invoice` shape — handling per-line discounts, shipping scalars, multi-currency exchange rate, cross-border tax routing.
3. POSTs `/v3.1/sales_invoices`, decodes the response, stores `(magento_entity_id ↔ sage_invoice_id)` in `entity_xref`.

On success, the worker calls `SyncRun::mark_succeeded(sync_run_id, sage_invoice_id)`. Then a follow-up `JobKind::PushSyncState` is enqueued — same chassis, different job kind — that POSTs the terminal status back to your Magento at `/rest/V1/byte8/sync-state`. Your `byte8_entity_sync_state` row flips from `pending` to `synced`.

## Step 6 — Verify

Refresh **Sales → Invoices** in your Magento admin. The chip is now `✓ Synced` (green). Hover for the Sage entity id; click into the invoice for the **Sage Accounting** info block with the timestamp.

Cross-check on the Byte8 dashboard at `ledger.byte8.io/dashboard/sync` — the run row shows `succeeded` with the resolved Sage entity id.

## Total elapsed time

Typical path on a healthy install:

| Step | Latency |
|---|---|
| Observer → outbox INSERT | < 5 ms |
| Pending chip appears | < 5 ms (write-through) |
| Cron picks up the row | 0–60 s (cron interval) |
| Outbox POST → chassis 202 | ~150 ms |
| Worker fetches canonical | ~80 ms |
| Sage POST | ~300–800 ms (Sage-side latency dominates) |
| Sync-state callback to Magento | ~100 ms |
| **Synced chip appears** | **typically 5–60 s after Submit Invoice** |

If your chip stays `⏳ Pending` for over 90 seconds, the cron is probably not running. See [Troubleshooting → Cron](/docs/troubleshooting#cron-not-running).
