---
sidebar_position: 2
title: Connect your Sage business
description: After pairing Magento, connect your Sage Business Cloud Accounting business to the chassis via standard OAuth. Tokens are stored encrypted at rest and refreshed automatically — you never see them in PHP.
---

# Connect your Sage business

Once your Magento install is paired (see [Pairing-code Connect flow](/docs/connect/pairing-code)), the next step is connecting the Sage business you want to sync into.

This happens **entirely on `ledger.byte8.io`** — the Magento module never sees a Sage OAuth token, never holds a Sage client secret, never makes a request directly against `api.accounting.sage.com`. The chassis owns Sage authentication centrally.

## The flow

### 1. Open the Sage connect page

`ledger.byte8.io/dashboard/connect-sage`. You can land there from:

- **First-run prompt** — after pairing Magento, the dashboard surfaces a "Connect your Sage account" CTA on the home tile.
- **Bindings page** — `dashboard/bindings/{id}` shows a "Sage not connected yet" banner with the same CTA.

### 2. Pick your Sage region

Sage runs region-specific OAuth flows + region-specific API base URLs:

| Region | API base | OAuth host |
|---|---|---|
| United Kingdom | `api.accounting.sage.com/v3.1` | `id.sage.com` |
| United States | `api.na.accounting.sage.com/v3.1` | `id.sage.com` |
| Ireland | `api.ie.accounting.sage.com/v3.1` | `id.sage.com` |
| Canada | `api.na.accounting.sage.com/v3.1` | `id.sage.com` |
| France / Spain / Germany | regional endpoints | `id.sage.com` |

Pick the region your Sage business lives in. The chassis routes every API call to the matching base URL automatically.

### 3. Authorise via Sage

Click **Connect Sage** — you're redirected to Sage's OAuth consent screen, scoped to:

- `full_access` — read + write on contacts, sales invoices, sales credit notes, products, services, stock items, ledger accounts, tax rates, bank accounts, contact payments, contact allocations.

If your Sage account has access to multiple businesses, Sage prompts you to pick which one to grant. **Pick exactly the one** you want this Magento install to sync into — the binding is locked to that business after consent.

Approve. Sage redirects back to `ledger.byte8.io` with an OAuth authorisation code.

### 4. Chassis exchanges + stores

The chassis exchanges the code for an access + refresh token pair. Both are stored **encrypted at rest** in the chassis database (AES-GCM with a server-side master key). The merchant never sees the plaintext token; the connector never has it on disk in PHP.

Access tokens are 5-minute-TTL on Sage's side; the chassis refreshes them transparently before each provider call (`OauthToken::load_fresh` in the worker).

### 5. Reference data prefetch

Immediately after connect, the chassis builds a **reference cache** for this Sage business: tax rates, ledger accounts (filtered to `visible_in=sales`), bank accounts, payment-method targets. This takes ~3-5 seconds and runs once. The dashboard's settings dropdowns (default tax rate, default ledger account, default bank account, payment-method map) populate from this cache.

Reference data is auto-refreshed once per 24 hours — see the [Default mappings](/docs/settings/default-mappings) page for how to manually refresh.

### 6. Done — your binding is live

The dashboard's **Bindings** page now shows a green binding row:

```
Magento your-shop.example.com  ↔  Sage your-business-name (UK)
                                                 [Settings] [Sync history]
```

From here, the next step is configuring sync policy — see [Sync settings](/docs/settings/sync-behavior). Most merchants ship with all defaults and never touch the policy.

## Why this design

We frequently get asked: *can I just give you my Sage username + password instead of OAuth?* No — Sage Business Cloud only exposes OAuth, and the chassis is built around it. This is also why:

- We don't need to ask for your Sage password ever.
- Token rotation (Sage's 5-minute access token TTL + 60-day refresh-token rotation) is invisible to you.
- Revoking access from Sage's side instantly disables the chassis from posting to your books — your data security stays under your control.

## Multiple Sage businesses

Each Magento binding talks to **one** Sage business. If you have multiple:

- One Magento install + multiple Sage businesses → spin up multiple bindings on the chassis (Bindings page → New binding) and pair separate Magento environments to each. The connector itself doesn't multiplex one Magento install onto N Sage businesses (that introduces ambiguity at every observer fire — which Sage business does *this* invoice go to?).
- Multiple Magento installs + one Sage business → pair each Magento install separately, point each binding at the same Sage business. Then use `website_filter` / `store_filter` in sync policy to scope which orders flow per binding.

Per-plan limits on the number of Sage businesses + Magento websites live on the [Plans & pricing page](https://byte8.io/products/sage-accounting#pricing).


## Disconnecting

See [Disconnect](/docs/connect/disconnect) — covers both the Magento side ("stop publishing") and the chassis side ("revoke the binding + invalidate Sage tokens").
