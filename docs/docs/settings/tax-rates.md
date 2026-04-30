---
sidebar_position: 4
title: Tax-rate map
description: Per-applied-tax-percent routing to specific Sage tax rates. When 20% goes to GB_STANDARD and 5% goes to GB_LOWER without manual line tagging.
---

# Tax-rate map

`ledger.byte8.io/dashboard/bindings/{id}/settings` → **Tax Rate Routing** card.

When a Magento invoice line carries a tax rate, the chassis routes it to a Sage `tax_rate_id` based on the rounded applied-tax percentage. The map defines per-percentage overrides; lines without a tax_rate (tax-exempt orders, manual lines) fall back to `default_tax_rate_id`.

## Why percentage keying (not Magento tax_class_id)

Magento has two ways to think about tax: *tax_class_id* (a numeric rich-semantic id like "Taxable Goods" vs "Reduced Rate") and *applied tax percentage* (the actual % computed at quote time). Two reasons we key on the percentage:

1. **Cheap.** Reading tax_class_id requires an N+1 product load on every invoice line. Reading the applied tax % is free — it's already on the line.
2. **Aligns with Sage's UX.** Sage's tax-rate picker shows "Standard 20%" — the merchant operator thinks in percentages, not class ids.

It's only lossy when two Magento tax classes resolve to the same applied %, in which case they map to the same Sage rate anyway — which is the right answer.

## The map shape

```
20.00 → GB_STANDARD
5.00  → GB_LOWER
0.00  → GB_ZERO
```

Keys are formatted as two-decimal strings (`"20.00"`, `"5.00"`, `"0.00"`). The chassis canonicalises whitespace + decimal places on save — `"20"` and `" 20.0 "` both become `"20.00"`.

## Adding a row

Click **+ Add mapping**. New row appears with a "Magento %" field (free input, validated as a number) and a **Sage rate** dropdown (populated from the `tax_rates` reference cache).

Type the percentage (e.g. `20`), pick the matching Sage rate. Save.

## Removing a row

Click the trash icon next to a row. Doesn't take effect until you click **Save** at the page header.

## What happens if a line's % isn't in the map

Falls back to `default_tax_rate_id` (set on the [Default mappings](/docs/settings/default-mappings) card). This is the "single-rate merchant" path — UK B2C selling only standard-rate goods, the default is `GB_STANDARD`, the map is empty, every line routes to standard.

For multi-rate merchants (mixed standard + zero-rated + reduced-rate goods), the map handles per-line routing.

## Cross-border invoices override the map

Non-GB customers (per their billing address country) force every line to `GB_ZERO` regardless of the map — Sage rejects standard rates on cross-border invoices with a `must be 'Zero Rated' for Customers outside the EU` 422.

This is **not configurable** by design — it's Sage's hard-server-side rule, not a Byte8 policy. See [Multi-currency](/docs/settings/multi-currency) §29 for the full cross-border tax flow.

## Validation

Field errors show inline:

- **Magento % key** — must be a non-negative number, gets canonicalised to `"%.2f"` on save. Invalid → `invalid_format`.
- **Sage rate id** — must be in the cached `tax_rates` list. Typos / stale rate ids → `not_in_reference`, red underline.

## Sage tax_rate_id reference

Common UK system rates (stable across Sage Business Cloud installs):

| Rate id | Label | Use |
|---|---|---|
| `GB_STANDARD` | Standard 20% | Most B2C goods |
| `GB_LOWER` | Lower 5% | Children's car seats, electricity, etc. |
| `GB_ZERO` | Zero rated | Children's clothes, exports, books |
| `GB_EXEMPT` | Exempt | Financial services, education |
| `GB_NO_TAX` | No tax | Out-of-scope |

Other regions (US sales tax, IE VAT, EU country VATs) have their own rate ids — see your Sage business's Tax Rates list.
