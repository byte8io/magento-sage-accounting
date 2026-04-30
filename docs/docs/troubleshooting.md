---
title: Troubleshooting
description: Operator-facing diagnoses for the most common sync failures — Sage 422s, dead-letter rows, the eight catalogued Sage v3.1 quirks, cron, auth drift, missing chips.
---

# Troubleshooting

The most common sync failures, in rough order of how often they hit. Each has a "symptom" (what you see in the admin or dashboard) and "fix" (what to do about it).

## Cron not running

**Symptom:** chips stay `⏳ Pending` indefinitely. Outbox row count grows.

**Diagnose:**
```bash
bin/magento cron:run --group=default
# Should run cleanly. If it errors, your install's cron pipeline is broken.
```

**Fix:** verify your system cron is calling Magento's cron entry every minute. Standard production setup:
```cron
* * * * * /bin/bash -c "cd /var/www/magento && bin/magento cron:run --group=default 2>&1 >> var/log/cron.log"
```

If using ECE or a managed Magento host, check their cron configuration page.

## Dead-letter banner shows up

**Symptom:** banner on the config page: "N dead-lettered events".

**Diagnose:**
```bash
bin/magento byte8:sage:outbox:inspect
```

Read the `last_error` column for the cause. Common categories:

- `HTTP 401 Unauthorized` → auth drift. Re-pair (see below).
- `HTTP 422 RecordInvalid: …` → Sage rejected the payload. Read on for the catalogued quirks.
- `HTTP 5xx` → chassis-side or Sage-side outage. Wait + re-queue.

**Fix:** see [Dead-letter banner](/docs/magento-admin/dead-letter-banner) for the full triage flow.

## Auth drift (401s)

**Symptom:** every outbox row dead-letters with `HTTP 401`. The chassis dashboard shows the Magento binding `magento_connection_status: token_revoked`.

**Cause:** the per-tenant `api_key` shared between Magento and the chassis has drifted. Most common cause: someone re-paired one side without storing the new code.

**Fix:** disconnect from the Magento config page (or the chassis dashboard), then re-pair with a fresh code. See [Pairing-code Connect flow](/docs/connect/pairing-code).

## Backfill pre-PR7 rows

**Symptom:** existing invoices that synced before PR7 was deployed show the `—` chip on the grid.

**Cause:** the chassis only writes `byte8_entity_sync_state` rows on terminal `mark_*` calls **after** PR7 deployed. Historical sync history exists in the chassis dashboard but doesn't have a Magento mirror row.

**Fix (option 1, single rows):** retry from the chassis dashboard (`ledger.byte8.io/dashboard/sync` → row → Retry). The retry re-fires terminal mark + the PushSyncState callback, which populates the Magento mirror.

**Fix (option 2, batch):** SQL backfill on the Magento side:
```sql
INSERT INTO byte8_entity_sync_state
    (entity_type, magento_id, provider, sync_status, last_sync_at)
SELECT 'invoice', i.entity_id, 'sage_accounting', 'synced', NOW()
FROM sales_invoice i
WHERE i.entity_id IN (<comma-list-of-already-synced-ids>);
```

**Fix (option 3, future):** wait for the planned `byte8:sage:sync-state:backfill` chassis CLI that walks `sync_runs WHERE status='succeeded' AND entity_type IN (...)` and enqueues a PushSyncState per row. Slated for v1.1.

## Sage v3.1 catalogued quirks

We've found and worked around **eight** non-obvious Sage Business Cloud v3.1 behaviours. Each is invisible to merchants (the chassis handles it) but worth knowing for log-reading:

### §7 — `currency_tax_amount` required per line

Sage requires this field on every invoice line, even single-currency. Was the source of historical "field is required" 422s. Now always-set by the chassis.

### §17 — Invoices silently mirror billing → delivery address

If you don't send `delivery_address` explicitly on a `POST /sales_invoices`, Sage copies billing → delivery silently — corrupting orders shipped to a different address than billed. The chassis always sends both explicitly to prevent this.

### §20 — `line_items.description` is the umbrella 422 message

Sage returns `[RecordInvalid] line_items.description: This field is required` for many unrelated validation failures (totals invariant, HTML entities in product names, etc.). The chassis intercepts the 4xx envelope, parses the actual `$source` field locator, and surfaces the real cause.

### §22 — HTML entities in line descriptions trigger 422

Magento product names like `Quest Lumaflex&trade; Band` carry raw HTML entities; Sage's input validator rejects them. The chassis decodes via `htmlentity` before sending.

### §23 — `contact.reference` 10-char hard limit

Sage caps contact `reference` at 10 chars (undocumented). The chassis emits `M{id}` for registered customers and `G{9-hex-from-SHA256(email)}` for guests — stable, deterministic, always 10 chars.

### §27-§31 — Multi-currency quirks

Five separate findings around exchange rates, per-currency contact lock, the misleading `default_currency_id` field, and EU goods/services type validation. All handled by the chassis when [Multi-currency](/docs/settings/multi-currency) features are used.

### §32 — Sage shipping VAT recompute

Sage's invoice-level shipping panel **re-computes** shipping VAT from `shipping_tax_rate × shipping_net`, ignoring the explicit `shipping_tax_amount: 0` we send. Was causing phantom outstanding balances on multi-currency test invoices.

**Workaround (live in the chassis):** when Magento says shipping is untaxed (`shipping_tax_amount == 0`), the chassis forces `shipping_tax_rate_id = "GB_ZERO"` regardless of the merchant's default rate. Sage's recompute lands on zero; invoice total matches.

If you ever see a `~£X.XX` outstanding balance on a Sage invoice that paid for the full Magento total, this quirk is the suspect — check the SI's shipping panel.

## Sage soft-delete reservation

**Symptom:** dashboard error like "Sage still holds a reserved reference 'M5'" when re-syncing a customer.

**Cause:** Sage's "delete contact" is actually a soft-delete — the `reference` field stays reserved. Your chassis tries to reuse the reference for a fresh customer create; Sage rejects.

**Fix:** there's no clean automation for this — Sage doesn't accept our PUT on deleted contacts. The chassis surfaces the error with an actionable message; the operator either:

1. **Hard-delete the soft-deleted contact** in Sage's UI (Settings → Deletion).
2. **Email Byte8 support** — we can manually update the chassis's `entity_xref` to point at the existing Sage contact if you've already manually re-created it.

This is rare in practice (you have to actively delete a synced Sage contact for it to fire).

## Live API probing for hard 422s

If a 4xx persists despite all the above, the fastest diagnostic is poking Sage's API directly with a known-good token:

1. Get the binding's current OAuth token from the chassis CLI:
```bash
cargo run -p ledger-cli -- oauth:status <binding-uuid> --reveal-token
# (Dev-only; production tokens never get revealed)
```

2. Curl Sage's v3.1 API with the token:
```bash
TOKEN='...'; BIZ='...'
curl -sS -H "Authorization: Bearer $TOKEN" -H "X-Site: $BIZ" \
  https://api.accounting.sage.com/v3.1/sales_invoices?items_per_page=5 | jq
```

3. Reconstruct the failing payload from the chassis worker logs (the WARN-level "Sage 4xx — full error envelope" line dumps the raw body), tweak fields one at a time until Sage accepts. The 4xx response carries `$source` field paths so you can pinpoint the offending key.

This is what we use to find new Sage quirks. If you hit something not in the catalogue above, send the worker log line + the failing canonical to `helo@byte8.io` — we'll add the workaround.

## When to email support vs DIY

- **DIY:** dead-letter rows for catalogued causes (ref-cache stale, payment method unmapped, sync filter excluded) → re-queue after fixing.
- **Email Byte8 support (`helo@byte8.io`):** Sage soft-delete refs, novel 422s not in the catalogue, billing / subscription questions, anything where the chassis state seems out of sync with what you see in Magento or Sage.

Include in the email: tenant id (visible on the chassis dashboard), the Magento `entity_id` of the affected invoice, and the worker log line if you have it.
