---
sidebar_position: 1
slug: /
title: Introduction
description: Byte8 Sage Accounting — hosted SaaS connector between Magento 2 and Sage Business Cloud Accounting. Invoices, credit notes, customers, products, payments. Multi-currency. Audited.
---

# Byte8 Sage Accounting

A hosted SaaS connector that syncs **Magento 2 → Sage Business Cloud Accounting** in near real time. Invoices, credit notes, customers, products, and payments — multi-currency aware, with full audit trail and operator-visible failure handling.

## Why this exists

Every existing Magento × Sage connector is a one-time-fee PHP extension with limited entity coverage and no central API maintenance. Sage breaks their v3.1 API every quarter; PHP-extension merchants pay for the patch.

Byte8 fixes the model:

- **The Magento module is thin.** Five observers, an outbox, a JWT-signed wire to our hosted ledger. No OAuth in PHP, no Sage API client on disk, no client secret.
- **The chassis is centrally hosted.** OAuth token rotation, Sage region routing, rate-limit governance, retry, dead-letter handling, audit trail — all in one Rust SaaS we host. Sage breaks something? We patch the chassis; every connected merchant gets the fix.
- **You get visibility where you live.** Sage Status chips on the Magento Sales → Invoices and Sales → Credit Memos grids; admin info block on each entity detail page. No need to context-switch to a separate dashboard for "did this invoice make it?"

## Where to start

If you've never installed it, the [Quick start](/docs/getting-started/quick-start) is 60 seconds: composer require, setup:upgrade, pair with `ledger.byte8.io`, done.

If you're a Magento dev who wants to understand the architecture before installing, jump to the [Pairing-code Connect flow](/docs/connect/pairing-code).

If you're the merchant operator (accountant / merchandiser) who'll actually use this day-to-day, head to the [Magento admin](/docs/magento-admin/sage-status-grid) section — that covers every chip, banner, and detail-page block you'll see.

If you're configuring sync behaviour for an unusual setup (B2C consolidation, multi-currency storefronts, custom payment-method routing), the [Sync settings](/docs/settings/sync-behavior) section walks every card on the ledger dashboard.

## What this connector is NOT

- **Not a one-time PHP extension.** It's a SaaS subscription. The Magento module is the client; the heavy lifting is on the chassis.
- **Not bidirectional in v1.** Magento is the source of truth for catalog, inventory, customers, and order documents. Sage is the accounting ledger of record. **Sage → Magento writeback is Enterprise-on-request scope** — needs a Sage webhook surface, Magento write endpoints, and conflict-resolution policy. Build only on a custom contract.
- **Not a tax advisor.** We pass through what Magento computes — VAT MOSS, EU OSS, post-Brexit reverse charge routing all happen because Magento + Sage know how, not because we re-implement tax logic. Cross-border tax routing helpers are in [Multi-currency](/docs/settings/multi-currency).
- **Not a Magento marketplace listing yet.** Distribution is via Composer + Cargoman (private Composer registry) for the closed-beta cohort; Magento Marketplace listing follows public launch.

## Module ecosystem

| Package | Role | Composer | Repo |
|---|---|---|---|
| `byte8/magento-sage-accounting` | The connector — installed by merchants | `composer require byte8/magento-sage-accounting` | [GitHub](https://github.com/byte8io/magento-sage-accounting) |
| `byte8/module-client` | Shared chassis (outbox, JWT, canonical REST) — pulled in transitively | — | private |
| `byte8/module-core` | Shared utilities — pulled in transitively | — | private |

Merchants install one package: `byte8/magento-sage-accounting`. Composer pulls in the other two automatically.
