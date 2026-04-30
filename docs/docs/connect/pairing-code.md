---
sidebar_position: 1
title: Pairing-code Connect flow
description: How the SaaS-initiated pairing-code flow links your Magento install to your Byte8 ledger tenant. Replaces the old Magento-initiated OAuth-callback design.
---

# Pairing-code Connect flow

The connector uses a **SaaS-initiated pairing-code handshake** rather than a Magento-initiated OAuth callback. The merchant generates a code in the Magento admin, pastes it into `ledger.byte8.io`, and the chassis calls back into Magento to seal the binding.

## Why pairing codes (not OAuth)

The connector's chassis already speaks Sage OAuth — adding *Magento*-side OAuth on top of that meant:

- Two OAuth flows on every connect (Sage + Magento)
- A Magento callback URL the merchant has to register somewhere
- A client secret on the Magento server's disk
- The chassis having to host a per-merchant redirect endpoint

Pairing codes collapse all of that. The Magento side never sees an OAuth client; the chassis never round-trips through a callback URL. One JWT-signed inbound call from chassis to Magento closes the loop.

## The walkthrough

### 1. Merchant opens the Magento config page

**Stores → Configuration → Byte8 → Sage Accounting**

The page renders three blocks:

- **Connection status** — red ("Not connected") on first install
- **Magento base URL** — display-only, read from `Magento\Framework\UrlInterface`. The merchant copies this into the `ledger.byte8.io` form
- **Pairing code** — empty, with a **Generate pairing code** button

### 2. Generate the code

Click **Generate pairing code**. The `PairingCode/Generate` controller:

1. Generates a random base32 code (12 chars, like `J3K7-XQ9F-RP2M`).
2. Stores `SHA-256(code)` in `byte8/sage_accounting/pairing_code_hash` and `time()` in `byte8/sage_accounting/pairing_code_issued_at`. Plaintext code is **never stored** — it's revealed once via session flash and shown on the next page render.
3. Redirects back to the config page with the code visible in the **Pairing code** field.

The TTL is **30 minutes** from generation. After that, the hash is treated as expired and the chassis rejects the pair attempt; the merchant generates a new one.

### 3. Open `ledger.byte8.io`

Click **Open Byte8 Ledger** (button beside the pairing code). This deep-links to `ledger.byte8.io/dashboard/connect-magento` with a return-to context.

(If the merchant has multiple Byte8 ledger tenants, they'll need to pick the right one before submitting — the dashboard's UI handles tenant switching.)

### 4. Paste the code into the dashboard

The Connect Magento page asks for two fields:

- **Magento base URL** — `https://your-shop.example.com` (no trailing slash)
- **Pairing code** — the one Magento just generated

Submit. The dashboard fires:

```
POST /v1/magento/connect
{ "magento_base_url": "...", "pairing_code": "..." }
```

against the chassis. The chassis:

1. Generates a random `api_key` (256-bit) — this becomes the shared secret between this Magento install and our chassis going forward.
2. Calls back into Magento at `POST <magento_base_url>/V1/byte8/setup/pair`:
   ```
   { "pairing_code": "J3K7-XQ9F-RP2M",
     "tenant_id": "abc123…",
     "byte8_api_key": "<generated 256-bit key>",
     "ledger_base_url": "https://ledger.byte8.io" }
   ```
   This is the **only** unauthenticated webapi call the connector exposes — the pairing code itself is the credential, and it's single-use.
3. Magento's `Setup/Pair` model verifies `SHA-256(pairing_code) == stored_hash` and that `now - pairing_code_issued_at <= 30 min`. If both pass, it stores the `tenant_id` and `api_key` (encrypted via Magento's `EncryptorInterface`) and clears the pairing-code state.
4. Returns `200 OK`. The chassis persists its binding row and returns success to the dashboard.

### 5. Connection status flips to green

Refresh the Magento config page — Connection status block is now green ("Connected to Byte8 Ledger as tenant abc123…"). A **Disconnect** button appears beside it (see [Disconnect](/docs/connect/disconnect)).

From this point on, the per-tenant `api_key` is used to derive HKDF subkeys (one for inbound, one for outbound) that sign every JWT exchanged between Magento and the chassis.

## Why the code is single-use + 30 minutes

- **Single-use** — once the chassis successfully pairs, the stored hash is cleared. A second `POST /V1/byte8/setup/pair` with the same code 422s. Prevents a leaked code from rebinding to a different chassis tenant.
- **30 minutes** — short enough that a code accidentally screen-shared on a support call expires before damage. Long enough for a merchant who got distracted to come back to it.

If a code expires before pairing completes, just generate a new one — the old hash is overwritten, no cleanup needed.

## What if I'm running multiple Magento environments?

Each Magento install (dev, staging, production) needs its own pairing. They're independent:

- Same chassis tenant can have multiple Magento bindings (one per environment).
- Each binding has its own `api_key`, its own outbox, its own sync state.
- Settings (sync policy, tax / ledger / bank account routing) are per-binding, configured separately per environment.

Standard practice: pair production first; spin up a separate chassis tenant for staging if you want isolated test data. The 14-day trial covers either path.

## Next

- [Sage OAuth](/docs/connect/sage-oauth) — what happens after pairing, when you connect your Sage business to the chassis.
- [Disconnect](/docs/connect/disconnect) — how to revoke the binding cleanly from either side.
