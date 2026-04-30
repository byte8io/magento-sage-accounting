---
sidebar_position: 3
title: Payment-method map
description: Magento payment-method code → Sage bank account routing. The map that decides which Sage bank account each payment lands in (or whether the invoice is left UNPAID for manual reconciliation).
---

# Payment-method map

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Payment Method Map** card.

When `invoice.paid` fires from Magento, the chassis decides which Sage `bank_account` the auto-payment lands in. The decision tree, in order:

1. **Mapped explicitly** — payment_method appears in the map → route to mapped bank account
2. **Mapped to `None`** — explicit "don't auto-pay" → invoice stays UNPAID in Sage (B2B / net-terms semantics)
3. **Unmapped, with `default_bank_account_id` set** — fallback to the default
4. **Unmapped, no default** — invoice stays UNPAID in Sage (same as case 2)

Cases 2 + 4 are the **B2B-friendly default** — for offline payment methods (cheque, bank transfer, purchase order), Magento marks the invoice paid when the merchant clicks "Generate Invoice" but the money hasn't actually landed. The accountant reconciles in Sage when the cheque clears.

## The dropdown

The Payment Method Map card lists every Magento payment method **active on your store** (sourced from a chassis-side `binding.magentoPaymentMethods` GraphQL query that hits `GET /V1/byte8/payment-methods` on your Magento). You don't need to type method codes — they autocomplete from the live list:

- `checkmo` (Cheque / Money order)
- `banktransfer` (Bank Transfer Payment)
- `purchaseorder` (Purchase Order)
- `stripe_payments` (Stripe — if installed)
- `klarna_kp` (Klarna — if installed)
- `adyen_cc` (Adyen Card — if installed)
- `paypal_express` (PayPal Express — if installed)
- ... etc

For each method, pick a Sage bank account from the second dropdown (filtered to active bank accounts only) — or **None** to explicitly leave invoices UNPAID.

## When to leave a method unmapped

The default behaviour for unmapped methods is to leave the invoice UNPAID. This is **correct** for:

- `checkmo` — cheque hasn't cleared yet
- `banktransfer` — money hasn't arrived in your account yet
- `purchaseorder` — pure B2B net-terms; invoice may sit at 30 / 60 / 90 days
- Any other "the merchant marks paid before the money lands" scenario

For these methods, leaving them unmapped means your Sage AR aging report stays accurate — invoices show as outstanding until the accountant reconciles when the money lands.

## When to map a method

Map a method when the payment is **actually settled** at the moment Magento fires `invoice.paid`:

- `stripe_payments`, `adyen_cc`, `paypal_express` — capture-at-invoice flows where the money is already in your merchant account.
- `klarna_kp` — Klarna pays you upfront; their downstream collection is their problem.
- Any "instant" or "captured" payment.

Pick the Sage bank account corresponding to where the money lands (your Stripe payouts account, your card-processing account, etc).

## Multiple methods → one bank account?

Allowed and common — multiple cards / wallets often deposit into one bank account. Just pick the same Sage account in the second dropdown for each method.

## What if I add a new payment method to Magento later?

The chassis re-fetches the live list every time you load the settings page. The new method appears in the autocomplete on next visit. Until you map it (or set a `default_bank_account_id`), payments via that method leave invoices UNPAID — same behaviour as unmapped.

## Skipping payment events globally

If you'd rather Sage *never* see payment events (your accountant reconciles everything manually in Sage from bank-statement imports), the cleanest setup is:

1. Leave every payment method unmapped.
2. Don't set `default_bank_account_id`.

Every `invoice.paid` Magento fires lands as `skipped_by_policy / payment_method_not_mapped` in the dashboard sync history. Your invoices reach Sage; your payments don't. Reconciliation happens in Sage as before.

## Cross-currency payments

When the invoice is in a non-base currency (e.g. EUR invoice on a UK Sage business), the auto-payment uses the same `exchange_rate` as the invoice — Sage requires the payment currency match the invoice currency. The chassis handles this automatically; no separate config knob.

## Validation

Field-level validation on save:

- **Magento payment-method code** — must be in the live list. Typos surface as `not_in_reference` field errors.
- **Sage bank account id** — must be in the cached `bank_accounts` list. Same red-underline treatment.

Both blocks save with a clear inline error rather than letting a misconfiguration dead-letter at sync time.
