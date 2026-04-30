---
sidebar_position: 6
title: Multi-currency
description: How EUR / USD storefronts post into a UK-base Sage business — per-currency contact dedup, exchange rate header, GB_ZERO cross-border tax routing, EU goods/services type resolution.
---

# Multi-currency

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Multi-currency** card.

If your Magento install runs storefronts in multiple currencies (GBP base + EUR / USD storefronts for EU / US customers), this card configures how those orders post into your Sage business.

The chassis automatically handles the wire-side mechanics — exchange rates, per-currency contact dedup, cross-border tax routing. The only knob you typically need is the EU goods/services type resolver below.

## What "happens automatically" without configuration

For non-base-currency invoices (e.g. EUR storefront on a UK Sage business):

- **`currency_id`** on the Sage invoice = the Magento order currency (`EUR`).
- **`exchange_rate`** on the Sage invoice header = `1 / Magento.base_to_order_rate` formatted to 10 decimal places. Sage auto-computes the per-line `base_currency_*` mirror fields from this header rate (Sage v3.1 quirk §27 — the per-line currency siblings the spec describes don't actually exist on the POST shape).
- **Per-currency contact** — Sage locks each contact to one transaction currency (Sage v3.1 quirk §31). The same Magento customer ordering across UK + EU + US storefronts gets one Sage contact per currency. The chassis dedups via `entity_xref` keyed `contact:GBP` / `contact:EUR` / `contact:USD`. Sage `displayed_as` gets a `(EUR)` / `(USD)` suffix; the 10-char `reference` gets a 1-char currency initial (`M5E` for EUR, `M5U` for USD) so you can tell them apart in Sage's contact picker.
- **Cross-border tax routing** — non-GB customers on a UK Sage business force every line to `tax_rate_id = "GB_ZERO"` (post-Brexit reverse charge — Sage v3.1 quirk §29). Required by Sage's server-side validator; not configurable.

## `default_eu_goods_services_type`

**Default:** `GOODS`.

Required on every line for cross-border invoices on a UK Sage business — Sage validates `eu_goods_services_type_id` ∈ {`GOODS`, `SERVICES`} on cross-border lines.

The chassis enforces an "all lines on one invoice share the same type" invariant (Sage requires this — mixed-type invoices 422 with `must be the same`). To resolve a single value, the chassis applies the rules below in order; the catch-all is whatever you set this default to.

## `product_type_eu_goods_map`

**Default:**
- `simple` → `GOODS`
- `configurable` → `GOODS`
- `bundle` → `GOODS`
- `grouped` → `GOODS`
- `virtual` → `SERVICES`
- `downloadable` → `SERVICES`

Per-Magento-product-type override map. Add rows for non-standard types your store uses (custom product types from third-party modules).

The chassis routes an invoice line to:

1. The map entry for the line's `product.type_id` (if present)
2. Otherwise the `default_eu_goods_services_type` catch-all

Mixed-type invoices (some lines `GOODS`, some `SERVICES`) coerce to the catch-all rather than 422 on the mix. Operator-visible: skip-reason `mixed_eu_goods_services_coerced_to_default` on the dashboard.

## Validation

- `default_eu_goods_services_type` must be `GOODS` or `SERVICES`. Anything else → red-underline `OUT_OF_RANGE`.
- Map values must be `GOODS` or `SERVICES`. Same validator, per-row.
- Map keys are free-form Magento product type strings — no validation (your store may have custom types).

## Domestic-only merchants

If your Magento and Sage live in the same country (e.g. UK Magento storefront + UK Sage business with no cross-border customers), this card is **a no-op**. Cross-border tax routing only kicks in for non-base-country customers; the EU goods/services type only matters when the GB_ZERO override fires. Domestic invoices use the rate from the [Tax-rate map](/docs/settings/tax-rates) and never touch this card.

## Sage shipping VAT quirk (§32)

One additional automatic behaviour worth knowing about: Sage's invoice-level shipping panel **re-computes** shipping VAT from `shipping_tax_rate_id × shipping_net_amount`, ignoring the explicit `shipping_tax_amount: 0` we send. This caused a phantom outstanding balance on early multi-currency test invoices.

The chassis works around it: when Magento says shipping is untaxed (`shipping_tax_amount == 0`), the chassis forces `shipping_tax_rate_id = "GB_ZERO"` regardless of the merchant's default rate. Sage's recompute then lands on zero and the invoice total matches what Magento declared. Documented in [Troubleshooting → Sage shipping VAT recompute](/docs/troubleshooting#sage-shipping-vat-recompute-32).

## Limitations

- **Single Sage business per binding.** One binding = one Sage business = one base currency. Multi-currency support is about the *transaction* currency, not the *base* currency. If you need to sync into a UK Sage business AND a US Sage business in the same Magento install, that's two separate bindings — see [Connect → Sage](/docs/connect/sage-oauth).
- **No FX gain/loss line.** Sage handles FX gain/loss internally based on payment-vs-invoice exchange rate drift; the chassis doesn't post separate FX lines.
- **No multi-currency products.** Product sync uses the Sage business base currency for prices. Magento per-currency overrides (`special_price` per-website with currency conversion) aren't transmitted.
