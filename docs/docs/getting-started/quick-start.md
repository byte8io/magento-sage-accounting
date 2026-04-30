---
sidebar_position: 1
title: Quick start
description: 60 seconds from composer require to a paired connection. The shortest path to a green Connection Status block in your Magento admin.
---

# Quick start

The 60-second path. For requirements, optional companion modules, and post-install verification, see [Installation](/docs/getting-started/installation).

## 1. Install the connector

```bash
composer require byte8/magento-sage-accounting
bin/magento module:enable Byte8_Core Byte8_Client Byte8_SageAccounting
bin/magento setup:upgrade
bin/magento cache:flush
```

`composer require` pulls in `byte8/module-client` (the shared chassis) and `byte8/module-core` (utilities) automatically.

## 2. Generate a pairing code

In the Magento admin: **Stores → Configuration → Byte8 → Sage Accounting**.

You'll see a **Connection Status** block (red, "Not connected") and a **Pairing code** field. Click **Generate pairing code** — Magento computes a fresh code, shows it once (it's stored hashed), and starts a 30-minute TTL.

## 3. Pair with `ledger.byte8.io`

Click **Open Byte8 Ledger** beside the pairing-code field — it redirects you straight to `ledger.byte8.io/dashboard/connect-magento` with the right tenant context.

Sign in (or create your Byte8 account if this is your first connector), enter:

- **Magento URL** — your store's base URL (`https://your-shop.example.com`)
- **Pairing code** — the one you generated 30 seconds ago

Submit. Ledger calls back into your Magento at `POST /V1/byte8/setup/pair`, persists the per-tenant `api_key`, and the Magento Connection Status block flips to green ("Connected").

## 4. Connect Sage

Still on `ledger.byte8.io`, navigate to **Connect → Sage** and complete the standard Sage OAuth handshake. The chassis stores the OAuth tokens encrypted at rest and refreshes them automatically — you never see them again.

After connecting, the chassis builds a **reference cache** for your Sage business: tax rates, ledger accounts, bank accounts, payment-method targets. This takes ~3-5 seconds and runs once per business; the dashboard's settings dropdowns populate from it.

## 5. Verify with a test invoice

Raise a test invoice in your Magento admin (Sales → Orders → Invoice). Within ~60 seconds (the cron drain interval), it should appear:

- **In your Magento admin** — Sales → Invoices grid, with the **Sage Status** chip flipping from `⏳ Pending` to `✓ Synced`.
- **In Sage** — listed as an outstanding sales invoice on the contact created from the Magento customer.
- **On the Byte8 dashboard** — `ledger.byte8.io/dashboard/sync` shows a `succeeded` row with the Sage invoice id (`displayed_as` like `SI-1`).

## What's next

If the test invoice synced cleanly, you're live. From here:

- [Tour the sync settings](/docs/settings/sync-behavior) — sync filters, default mappings, payment-method routing, multi-currency knobs, B2C consolidation. All optional; defaults are sensible.
- [Browse the Magento admin surfaces](/docs/magento-admin/sage-status-grid) — chips, info blocks, dead-letter banner, every visual confirmation you'll see day-to-day.
- [Read what syncs](/docs/what-syncs) — the entity-by-entity matrix.
- If something didn't sync, head to [Troubleshooting](/docs/troubleshooting).
