# Changelog

All notable changes to `byte8/module-sage-accounting` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Version coordinates match `composer.json` — bump on every release that
touches merchants, link the relevant Sage Accounting `__docs/PROGRESS.md`
slice from the byte8.io repo when a backend change is involved.

## [Unreleased]

_No unreleased changes yet._

## [1.0.1] — 2026-04-29

### Added

- `README.md` — connector overview, Connect-flow walkthrough, sync
  matrix, architecture diagram, install + console-command reference.
- `LICENSE.txt` — MIT, matching the sibling `module-core` /
  `module-profile` / `module-utils` modules.
- `CHANGELOG.md` (this file) — version-to-version diff for merchants
  pulling updates via Cargoman / Composer.

## [1.0.0] — 2026-04-29

First production release. Three coherent slices land together (PR1–PR2
pairing-code Connect, PR2–PR6 outbound sync observers, PR7 Magento-side
sync-status mirror UI). Pair-installs with `byte8/module-client` and
`byte8/module-core`; the Sage API itself is reached from the Byte8
Ledger SaaS — Magento never talks to `api.accounting.sage.com` directly.

### Added

#### Connect flow (PR1 / PR2)

- Pairing-code admin field, **Generate pairing code** button, and a
  **Open Byte8 Ledger** redirect block. SHA-256 hash of the code stored
  in `byte8/sage_accounting/pairing_code_hash` with a 30-min TTL.
- `POST /V1/byte8/setup/pair` webapi endpoint (Bearer-JWT auth via the
  `Byte8_Client::byte8_webapi` ACL) — ledger calls in to complete the
  handshake and persist the per-tenant `api_key`.
- Connection status block: pre-Connect, paired, and dead-letter banner
  states.

#### Outbound sync observers (PR2 / PR3 / PR4 / PR5 / PR6)

- `InvoiceCreatedObserver` (`invoice.created`) — fires on first save;
  posts unpaid invoices to Sage as outstanding AR.
- `InvoicePaidObserver` (`invoice.paid`) — fires on PAID transition;
  attaches a `contact_payments` row + `contact_allocations` against the
  matching Sage invoice.
- `CreditMemoCreatedObserver` (`creditmemo.created`) — handles refunds,
  including offline-payment-method credit memos with no parent invoice.
- `CustomerUpsertedObserver` (`customer.upserted`) — fires on
  `customer_save_after`; per-currency contact dedup is handled
  ledger-side via `entity_xref`.
- `ProductUpsertedObserver` (`product.upserted`) — wired to BOTH
  `catalog_product_save_after` AND `cataloginventory_stock_item_save_after`
  so catalog + stock saves on the same product collapse to a single
  ledger sync via the shared idempotency key.
- All observers use stable idempotency keys (e.g.
  `invoice.created:{entity_id}`) so observer re-fires, duplicate saves,
  and replays are safe.
- `byte8:sage:invoice:sync <id>` console command — operator-driven sync
  trigger for triage / dev (production sync goes through the standard
  observer + cron path).

#### Magento-side sync-status mirror UI (PR7)

- **Sales → Invoices grid** — "Sage Status" column with chips
  (`✓ Synced` / `⏳ Pending` / `⏸ Skipped` / `✗ Failed` / `—`).
  Sortable + filterable. Hover for Sage entity id, skip-reason, or
  error-code. Implemented as `Model\ResourceModel\Sales\InvoiceGridCollection`
  — a `SearchResult` subclass that bakes the `byte8_entity_sync_state`
  LEFT JOIN into `_initSelect()`. Magento's stock virtualType is
  redefined to point at this subclass via `di.xml` (the plugin path
  fails because the stock collection is a virtualType with no concrete
  PHP file, so no Interceptor can be generated).
- **Sales → Credit Memos grid** — same column. Implemented as
  `Model\ResourceModel\Sales\CreditmemoGridCollection` — subclasses
  Magento's real concrete `Order\Creditmemo\Grid\Collection`, wired
  via the `CollectionFactory.collections` override. Plugin path was
  tried first and crashed (`joinLeft on null`) because
  `AbstractCollection::setMainTable` calls `getSelect()` from inside
  the constructor *before* `_initSelect` populates the select — the
  subclass + `_initSelect` override sidesteps the timing issue.
- **Invoice + Credit Memo detail pages** — new "Sage Accounting" admin
  info block beside "Order Information": status chip, Sage reference,
  last sync timestamp, skip-reason / error-code context. Reads
  `byte8_entity_sync_state` by `(entity_type, magento_id, provider)`.
- **Write-through pending mirror** — observers pass
  `SageConfigInterface::PROVIDER_KEY` as the new 4th arg to
  `ByteClient::enqueueEvent`, which UPSERTs a `pending` row in
  `byte8_entity_sync_state` immediately (before the cron drain or the
  ledger callback). Grid chip appears the moment the merchant raises
  an invoice — no waiting for the round-trip.
- **Chip styling** — `view/adminhtml/web/css/sync-status.css` loaded
  via `default.xml`; `bodyTmpl=ui/grid/cells/html` on the grid column
  so the styled span renders as markup instead of escaped text.

### Notes for operators

- `bin/magento setup:upgrade` is required after install — the `byte8/module-client`
  dependency adds the `byte8_entity_sync_state` table that this module
  reads from in its grid + detail UI.
- Pre-PR7 invoices show the `—` chip (no mirror row exists). Either
  retry from the ledger dashboard (re-fires the callback) or backfill
  via SQL — see `MAGENTO_THIN_MODULE.md` "Backfill" section.

### Intentionally NOT in v1

- **No "Sage Status" chip on Sales → Orders grid or order detail page.**
  Orders aren't synced directly (we sync invoices, 0..N per order).
  A row-level chip would either need a synthetic rollup (obscuring
  multi-invoice partial-sync state) or arbitrarily pick one invoice.
  Operators drill into the Invoices tab from the order.
- **No "Sage Status" chip on the Customers grid.** Per-currency
  contact keying (PR6) means one Magento customer maps to N Sage
  contacts — a single chip would be a lie. Customer detail-page block
  with all per-currency references is the planned v1.1+ surface.
- **No "Open in Sage" deep link.** Sage's business URL slug isn't
  deterministic across regions and isn't returned in the OAuth flow.
  Probe once we have a US or IE design partner.
- **No `provider_reference`** (e.g. `SI-27`) populated yet — column
  exists in the schema, ledger sends `null` today. v1.1+ — needs a
  getter on the provider trait so `displayed_as` rides back with the
  Sage create response.
- **No manual "Resync now" button on Magento detail pages** — point
  operators at the dashboard's per-row retry.
- **No standalone `payment.captured` flow** for offline payments —
  Magento has no API to attach an offline payment to an existing
  invoice; accountants reconcile manually in Sage when the cheque /
  bank transfer lands.

[Unreleased]: ../../compare/v1.0.1...HEAD
[1.0.1]: ../../compare/v1.0.0...v1.0.1
[1.0.0]: ../../releases/tag/v1.0.0
