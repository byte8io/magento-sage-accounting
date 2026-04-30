---
sidebar_position: 2
title: Installation
description: System requirements, Composer install, optional Cargoman registry setup for closed-beta merchants, network requirements, and post-install verification.
---

# Installation

## Requirements

| Component | Minimum | Notes |
|---|---|---|
| Magento | 2.4.4 | 2.4.7 / 2.4.8 recommended |
| PHP | 8.1 | 8.2 / 8.3 / 8.4 / 8.5 supported |
| MySQL | 8.0 | MariaDB 10.6+ also works |
| Outbound HTTPS | port 443 | To `ledger.byte8.io` only — no direct connection to Sage |
| Composer | 2.x | 2.7+ recommended |

The connector is a **SaaS subscription**. You'll also need:

- A Byte8 account at [byte8.io](https://byte8.io) — free 14-day trial, no card required.
- A Sage Business Cloud Accounting subscription that you control (you OAuth your own Sage business into the chassis after pairing the Magento side).

The connector itself doesn't store any credentials in PHP — Sage OAuth tokens live encrypted in our hosted ledger. The only secret stored on your Magento side is the per-tenant `api_key` (encrypted via Magento's `EncryptorInterface` in `core_config_data`), and even that is set automatically by the pairing handshake.

## Install

```bash
composer require byte8/magento-sage-accounting
bin/magento module:enable Byte8_Core Byte8_Client Byte8_SageAccounting
bin/magento setup:upgrade
bin/magento setup:di:compile         # production mode only
bin/magento cache:flush
```

`composer require byte8/magento-sage-accounting` transitively pulls:

- `byte8/module-client` — shared chassis (outbox, JWT auth, canonical REST endpoints, sync-state mirror table)
- `byte8/module-core` — Magento utilities the chassis depends on

Three Magento modules end up enabled: `Byte8_Core`, `Byte8_Client`, `Byte8_SageAccounting`. Verify with:

```bash
bin/magento module:status | grep Byte8
```

## Cargoman (closed beta only)

While the connector is in closed beta, packages live on **Cargoman** (Byte8's private Composer registry) rather than Packagist. Add the registry to your project's `composer.json` before the `composer require`:

```bash
composer config repositories.byte8 composer https://cargoman.byte8.io/<your-key>/
composer require byte8/magento-sage-accounting
```

The registry URL is in the welcome email Byte8 sends after you sign up for the trial. After public launch, the package will be available on Packagist directly without registry config.

## Post-install verification

```bash
bin/magento module:status Byte8_SageAccounting
# → Module is enabled

bin/magento config:show byte8/sage_accounting/tenant_id
# → empty (you haven't paired yet — fine)

bin/magento dev:tests:run unit -- Byte8/SageAccounting   # optional sanity check
```

In the admin: **Stores → Configuration → Byte8 → Sage Accounting** should render a config page with a red "Not connected" status block + a **Generate pairing code** button. That's the install verification.

## Database changes

`bin/magento setup:upgrade` creates two tables (both owned by `byte8/module-client`):

- `byte8_event_outbox` — transport queue for outbound events. Drained by the `byte8_outbox_drain` cron every minute.
- `byte8_entity_sync_state` — Magento-side mirror of ledger sync outcomes. Drives the Sage Status chips on the admin grids and detail pages.

The connector itself adds Magento config rows under `byte8/sage_accounting/*` for the pairing flow but no new tables.

## Cron

One cron is registered (in `byte8/module-client`):

```xml
<job name="byte8_outbox_drain" instance="Byte8\Client\Cron\OutboxDrain" method="execute">
    <schedule>* * * * *</schedule>
</job>
```

A daily cleanup job (`byte8_outbox_gc`) GCs `succeeded` outbox rows older than 30 days. No per-provider crons.

If your Magento install doesn't have cron running (`bin/magento cron:run` from system cron, or the `magento_cron` job in production), the outbox will fill up and never drain. Verify cron is healthy with:

```bash
bin/magento cron:run --group=default
```

## Uninstall

```bash
bin/magento module:disable Byte8_SageAccounting Byte8_Client Byte8_Core
composer remove byte8/magento-sage-accounting
bin/magento setup:upgrade
```

This stops the observers from firing and removes the modules from the autoload. The `byte8_event_outbox` and `byte8_entity_sync_state` tables stay (audit data); drop them manually if you want a clean slate. Disconnect on the ledger side from **Stores → Configuration → Byte8 → Sage Accounting → Disconnect** *before* removing the modules so the chassis flips its binding row to `revoked`.

## Network requirements

- **Outbound from Magento:** HTTPS to `ledger.byte8.io` (port 443) only. No direct connection to `api.accounting.sage.com` from Magento.
- **Inbound to Magento:** the chassis posts to your Magento's `/rest/V1/byte8/*` endpoints (canonical entity getters, sync-state callback). These need to be reachable from `ledger.byte8.io` over HTTPS — same surface every Magento install already exposes for its REST API.

If your Magento is behind a WAF / IP allowlist, allow the byte8.io egress IP range (in your welcome email).
