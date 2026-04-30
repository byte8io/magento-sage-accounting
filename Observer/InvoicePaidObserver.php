<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Observer;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

/**
 * Publishes `invoice.paid` when a Magento invoice transitions to the
 * PAID state — ledger turns this into an `AttachInvoicePayment` job
 * that POSTs a CUSTOMER_RECEIPT to Sage against the already-existing
 * invoice entity (created by `InvoiceCreatedObserver` → `invoice.created`).
 *
 * The two-event split is the backbone of correct B2B / net-terms
 * support: Sage must see the invoice as outstanding AR the moment
 * Magento raises it (via `invoice.created`), then see the payment
 * later when the merchant captures (via `invoice.paid`). Collapsing
 * both into one event would silently drop unpaid invoices from Sage.
 *
 * Fires exactly once on either transition path:
 *   - fresh-PAID insert (capture-online): orig_state=null, state=PAID
 *   - OPEN → PAID update (capture-offline): orig_state=OPEN, state=PAID
 * No-op saves of an already-paid invoice skip via the orig_state guard.
 *
 * Payload is intentionally id-only — observers run inside the save
 * transaction and the row may still be evolving. Ledger's worker
 * refetches the canonical invoice via `GET /V1/byte8/invoice/:id` once
 * it picks up the event.
 */
class InvoicePaidObserver implements ObserverInterface
{
    public function __construct(
        private readonly ByteClientInterface $byteClient,
        private readonly SageConfigInterface $sageConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->sageConfig->isConnected()) {
            return;
        }

        // `_save_after` ships the entity under `object`; `_pay` shipped
        // it under `invoice`. Support both so a future move of the hook
        // doesn't silently break the observer.
        $event = $observer->getEvent();
        $invoice = $event->getData('object') ?? $event->getData('invoice');
        if (!$invoice instanceof InvoiceInterface) {
            return;
        }

        // Fire exactly once on the state transition → PAID:
        //   - fresh insert as PAID (Capture Online):
        //       orig_state = null (=> 0), state = 2  → transition ✓
        //   - OPEN → PAID update (Capture Offline):
        //       orig_state = 1, state = 2           → transition ✓
        //   - re-save while already PAID:
        //       orig_state = 2, state = 2           → skip
        //   - PAID → CANCELED, etc.:
        //       state ≠ 2                           → skip (first guard)
        //
        // The idempotency key below is a second line of defence for
        // any path that bypasses getOrigData (DB-level UPDATE, admin
        // replay, etc.) — same key collapses into one ledger sync_run.
        $state = (int) $invoice->getState();
        if ($state !== Invoice::STATE_PAID) {
            return;
        }
        $origState = $invoice->getOrigData('state');
        if ($origState !== null && (int) $origState === Invoice::STATE_PAID) {
            return;
        }

        $entityId = (int) $invoice->getEntityId();
        if ($entityId <= 0) {
            return;
        }

        try {
            // enqueueEvent == outbox-only: keep save transactions fast.
            $this->byteClient->enqueueEvent('invoice.paid', [
                'magento_entity_id' => $entityId,
                'website_id'        => $this->resolveWebsiteId($invoice),
                'store_id'          => (int) $invoice->getStoreId(),
                'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'           => [
                    'increment_id' => (string) $invoice->getIncrementId(),
                ],
            ], 'invoice.paid:' . $entityId, SageConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: invoice.paid observer failed to enqueue for invoice ' . $entityId
                . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Invoice doesn't carry website_id directly — derive via the order.
     * Both `getOrder()` and an `order` getter exist depending on the
     * Magento version; fall back to 0 if neither is present (ledger
     * side can still resolve from store_id).
     */
    private function resolveWebsiteId(InvoiceInterface $invoice): int
    {
        if (method_exists($invoice, 'getOrder')) {
            $order = $invoice->getOrder();
            if ($order && method_exists($order, 'getStore')) {
                $store = $order->getStore();
                if ($store && method_exists($store, 'getWebsiteId')) {
                    return (int) $store->getWebsiteId();
                }
            }
        }
        return 0;
    }
}
