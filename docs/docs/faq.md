---
title: FAQ
description: Common questions about regions, multi-store, security, data residency, supported entities, and what happens to your data if you cancel.
---

# FAQ

## Plans & billing

For pricing, tier comparison, free-trial terms, money-back guarantee, and overage policy, see the **[Plans & pricing page on byte8.io](https://byte8.io/products/sage-accounting#pricing)**. We keep all commercial details there so this docs site stays purely about the product behaviour.

The Magento module itself (`byte8/magento-sage-accounting` + `byte8/module-client` + `byte8/module-core`) is MIT-licensed and free to install. The connector — what makes the module talk to Sage — is the SaaS subscription. You install the module *and* sign up for a Byte8 plan; the two together make the connector work.

## Regions

### Which Sage Business Cloud regions are supported?

UK, US, Ireland, Canada, France, Spain, Germany. Pick the region during the [Sage OAuth flow](/docs/connect/sage-oauth) — the chassis routes to the matching API endpoint automatically.

### Magento storefront in one country, Sage business in another?

Supported. The connector handles per-currency contact dedup and cross-border tax routing automatically — see [Multi-currency](/docs/settings/multi-currency).

### Multiple Sage businesses (multi-region)?

Each Magento binding maps to one Sage business. For multi-region setups, spin up multiple bindings on the chassis and either: (a) use `website_filter` / `store_filter` per binding to scope which Magento orders flow to which Sage business, or (b) pair separate Magento environments per binding. Per-plan limits on the number of bindings live on the [Plans & pricing page](https://byte8.io/products/sage-accounting#pricing).

## Multi-store / multi-website

### Magento has 5 websites — does each need its own binding?

No. One binding can sync any number of Magento websites into one Sage business. Use `website_filter` on the sync policy if you only want some websites flowing.

### Per-website Sage businesses?

Each binding pairs to one Sage business; the merchant maps Magento `website_id`s to bindings via `website_filter`. Per-plan limits on the number of Sage businesses are on the [Plans & pricing page](https://byte8.io/products/sage-accounting#pricing).

### Can two bindings share the same Sage business?

Technically yes (the chassis doesn't prevent it), but it'll cause `entity_xref` conflicts on the same customer / product appearing on both bindings. Don't.

## Security

### Where do my Sage OAuth tokens live?

Encrypted at rest (AES-GCM) in the chassis database. Never on your Magento server, never in PHP, never in Magento config. The chassis refreshes them transparently before each provider call.

### Can Byte8 staff access my Sage data?

The chassis logs Magento entity ids, Sage entity ids, sync status, and error messages — never invoice line content or customer PII beyond what's necessary for diagnosing failures. Token-level Sage access is restricted to the worker process; no Byte8 staff has interactive access to your Sage tokens.

### What's the inbound webapi attack surface on my Magento?

The connector exposes 7 REST endpoints under `/V1/byte8/*`:

- `GET /V1/byte8/{ping,payment-methods,invoice/:id,customer/:id,creditmemo/:id,payment/:id,product/:id}`
- `POST /V1/byte8/sync-state`

All 7 are JWT-authed via `JwtUserContext` against the per-tenant `api_key`. The synthetic ACL plugin grants the JWT-authed integration user access to `Byte8_Client::byte8_webapi` only — no scope to cart, customer-create, admin, or any core Magento resource.

The pairing-code endpoint (`POST /V1/byte8/setup/pair`) is the **only** unauthenticated webapi route, and it accepts requests only when a fresh-within-30-min pairing-code hash matches.

### What if I want to revoke chassis access immediately?

Disconnect from `ledger.byte8.io/dashboard/bindings/{id}` → Disconnect binding. Within seconds the chassis flips the binding to `revoked`, stops dispatching jobs, and revokes Sage tokens at Sage. The Magento side will start dead-lettering subsequent observer-fired events; the dead-letter banner surfaces the count.

For nuclear-option: rotate your Magento `api_key` in `core_config_data` directly. The chassis's signed JWTs against the old key will 401; the binding effectively goes dark immediately.

## Data

### Where does the chassis run?

UK + EU regions on Hetzner Cloud (Falkenstein + Helsinki), with database in eu-west-1. US region planned for the first US design partner — until then US-Sage merchants are served from EU with the cross-Atlantic latency.

### What data leaves my Magento?

Every observer-fired event publishes a JSON payload to the chassis. The shapes are documented in `apps/ledger/__docs/LEDGER_INTEGRATION_SPEC.md` — basically the canonical Magento entity (snake_case) for invoices / credit memos / customers / products, plus minimal context (`magento_entity_id`, `website_id`, `store_id`, `occurred_at`).

Payment card details, Magento admin user PII, and any Magento entity not explicitly listed in [What syncs](/docs/what-syncs) **never leave Magento** — the connector simply doesn't read or transmit them.

### What happens if I cancel my subscription?

- Chassis stops dispatching new jobs after the billing period ends.
- The Magento module disconnects (auto-flips to "Not connected" on cancellation).
- Your historical sync data (`sync_runs`, `entity_xref`) stays in the chassis database for 90 days for audit; after 90 days it's purged.
- Your Sage data is untouched — every Sage entity the chassis created stays in Sage. You don't lose your accounting history.
- Re-subscribe within 90 days to restore the binding without re-OAuthing Sage.

## Entities

### Why no Sage → Magento sync?

It's Enterprise on request — not a v1 feature. Doing it well needs Sage webhook surface (Sage's webhooks are sparse), Magento write endpoints for products / contacts / inventory, and a conflict-resolution policy that no design partner has asked for. We'll build it on a custom contract for an Enterprise merchant who requests it.

### Why no `payment.captured` for offline payments?

Magento doesn't have an API to attach an offline payment (cheque clearing, bank transfer landing) to an existing invoice after the fact. So our chassis can't reliably link the Sage payment to the right Sage invoice. Best practice: leave invoices UNPAID in Sage, accountant manually reconciles when the money lands. Aligns with how every Sage user already handles AR.

### Estimates and quotes?

Magento → Sage estimates supported on higher tiers — see the [Plans & pricing page](https://byte8.io/products/sage-accounting#pricing) for tier-by-tier feature gating. Sage → Magento conversion (turn an accepted Sage estimate into a Magento order) is deferred — needs Magento write endpoints + commerce-side ordering logic that's adjacent to the order creation flow.

### Stock-level sync direction?

One-way M → S. Magento is the source of truth. Stock changes you make directly in Sage will drift; the next Magento save reconciles via the snapshot model + a `STOCKTAKE` movement row. Bidirectional stock is Enterprise on request.

## Compatibility

### Adobe Commerce Cloud (ECE)?

Should work — pure Composer, no infrastructure dependencies. Confirm with first ECE design partner; nothing in the architecture suggests issues.

### Hyvä storefront?

The connector has zero frontend assets — all observers fire on the backend. Hyvä is fully supported; nothing to configure differently.

### Magento 2.3 support?

No. 2.4.4 is the floor (MariaDB / MySQL feature dependencies). If a single design partner needs 2.3, contact us.

### B2B Company Accounts?

Higher tiers handle the B2B-specific flows (Magento `Company` entities → Sage `contact` with company name, share-of-spend reporting via `b2c_consolidation always_individual_for_b2b`). See the [Plans & pricing page](https://byte8.io/products/sage-accounting#pricing) for tier gating. Quote-to-order from Sage's side is the deferred bidirectional piece.
