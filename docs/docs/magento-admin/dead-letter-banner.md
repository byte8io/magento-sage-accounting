---
sidebar_position: 3
title: Dead-letter banner
description: The admin config page banner that surfaces failed deliveries — and how to triage them via the byte8:client:outbox CLIs.
---

# Dead-letter banner

When the outbox classifies a delivery failure as **deterministic** (a 4xx from the chassis, or 10 consecutive transient retries with no success), the row goes to `dead_lettered` status and a **banner appears on the Sage Accounting config page**:

> ⚠ **3 dead-lettered events** — these failed permanently and won't retry. Use `bin/magento byte8:client:outbox:inspect` to triage.

The banner sits inside the **Connection Status** block at the top of **Stores → Configuration → Byte8 → Sage Accounting**.

## When does a row dead-letter?

The chassis distinguishes deterministic from transient failures:

| Failure mode | Classification | Behaviour |
|---|---|---|
| Chassis returns 4xx (validation, auth, malformed payload, …) | **Deterministic** | Dead-letter on first attempt — retrying won't help |
| Chassis returns 5xx | Transient | Exponential backoff, up to 10 attempts (~7 days), then dead-letter |
| Network error / DNS / timeout | Transient | Same |
| Successful 2xx | Success | Mark `succeeded` |

The 24h silent-drop behaviour from earlier versions is gone — nothing is ever silently lost. Either it succeeds, or it dead-letters where you can see it.

## Triage from the CLI

### List dead-lettered rows

```bash
bin/magento byte8:client:outbox:inspect
```

Shows entity_id, event_name, idempotency_key, last_status_code, last_attempt_at, and the first 200 chars of `last_error`. Most operators run this from a support session.

### Re-queue after fixing the underlying cause

```bash
bin/magento byte8:client:outbox:requeue <entity_id>
```

Flips the row back to `pending`, clears `next_attempt_at`, lets the cron pick it up on the next minute. **Do this only after fixing whatever caused the original failure** — re-queueing a row whose cause is unfixed just dead-letters it again.

Common fixes before re-queue:

- **Validation 4xx** — the binding's sync policy referenced a stale Sage tax-rate / ledger-account / bank-account id. Refresh reference cache + correct the policy on the dashboard.
- **Auth 4xx** — the per-tenant `api_key` drifted (re-pair without storing the new one). Fix: disconnect + re-pair the binding (see [Connect → Disconnect](/docs/connect/disconnect)).
- **Provider 422** — Sage rejected the payload for a reason in the catalogue (see [Troubleshooting](/docs/troubleshooting)). Fix the cause, re-queue.

### Cleanup old succeeded rows

```bash
bin/magento byte8:client:outbox:cleanup --days=30
```

Purges `succeeded` outbox rows older than `--days` (default 30). Never touches `pending` or `dead_lettered`. The chassis already runs this daily via the `byte8_outbox_gc` cron — manual invocation is for ad-hoc cleanup.

## Why is the banner sticky until I act?

By design. The banner stays until you either:

1. Successfully re-queue (the row flips back to `succeeded` after the cron's next attempt → drops out of the dead-lettered count → banner disappears), or
2. Manually delete the dead-lettered row (`DELETE FROM byte8_event_outbox WHERE status='dead_lettered' AND entity_id=…`).

The "permanent failures need operator action" model is intentional. Silent timeout / drop loses accounting data; an obnoxious banner forces triage.

## What if the banner shows huge counts?

Most likely cause: the chassis side is unreachable / unresponsive for an extended period and every retry timed out into dead-letter. Diagnostic:

```bash
# Is the chassis reachable from this Magento install?
curl -i https://ledger.byte8.io/v1/tile/health
```

If the chassis is unreachable, the dead-letter pile won't help — fix connectivity first, then re-queue in batches:

```bash
bin/magento byte8:client:outbox:inspect | head -50          # see top-50 oldest
bin/magento byte8:client:outbox:requeue <id> ...            # re-queue them
```

The cron drains 50 per minute by default, so even 1,000 dead-lettered events catch up within ~20 minutes once unblocked.

## Rate-limiting risk

If you re-queue 1,000+ rows at once, you may hit Sage's per-tenant rate limit on the chassis side. The chassis backs off automatically (5xx → exponential retry → eventually succeeds within a few hours), but it'll temporarily thrash the dashboard with `rate_limited` errors. Best practice: re-queue in batches of 100-200, watch the chassis dashboard for errors, batch the next 200 once the first batch clears.
