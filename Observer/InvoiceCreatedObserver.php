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
 * Publishes `invoice.created` on the **first** save of a Magento invoice
 * (the entity_id ↔ orig_data edge). Ledger responds by POSTing the
 * invoice to the provider as outstanding AR — regardless of whether the
 * invoice is OPEN or already PAID at creation.
 *
 * Why a separate event from `invoice.paid`:
 *
 * - B2B / net-terms / COD / bank-transfer flows create invoices as OPEN
 *   (unpaid). The merchant needs them in Sage as outstanding receivables
 *   the moment they're raised, not only once the money lands. The old
 *   "only fire on PAID" design silently dropped every unpaid invoice.
 *
 * - For the common capture-online path where Magento inserts the
 *   invoice already PAID, BOTH events fire on the same save: ledger's
 *   Redis queue processes them FIFO (invoice.created declared first in
 *   events.xml) so the Sage invoice is created before the payment
 *   attaches to it.
 *
 * Idempotency key `invoice.created:<entity_id>` + ledger's entity_xref
 * dedup guarantee one Sage invoice per Magento invoice, however many
 * times this observer fires.
 */
class InvoiceCreatedObserver implements ObserverInterface
{
    public function __construct(
        private readonly ByteClientInterface $byteClient,
        private readonly SageConfigInterface $sageConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/dev.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->debug(print_r([
            '$this->sageConfig->isConnected()' => $this->sageConfig->isConnected()
        ], true), []);

        if (!$this->sageConfig->isConnected()) {
            return;
        }

        $event = $observer->getEvent();
        $invoice = $event->getData('object') ?? $event->getData('invoice');
        if (!$invoice instanceof InvoiceInterface) {
            return;
        }

        $logger->debug(print_r([
            'origData' => $invoice->getOrigData('entity_id')
        ], true), []);

        // "First save" == no prior entity_id in memory → this save is
        // the INSERT. Subsequent updates (state flips, adjustments,
        // tax recalcs, etc.) skip this observer cleanly. The PAID
        // transition is caught by InvoicePaidObserver separately.
        if ($invoice->getOrigData('entity_id') !== null) {
            return;
        }

        // Only publish for real accounting states — CANCELED invoices
        // should never appear in Sage AR.
        $state = (int) $invoice->getState();

        $logger->debug(print_r([
            '$state' => $state
        ], true), []);

        if ($state !== Invoice::STATE_OPEN && $state !== Invoice::STATE_PAID) {
            return;
        }

        $entityId = (int) $invoice->getEntityId();

        $logger->debug(print_r([
            '$entityId' => $entityId
        ], true), []);

        if ($entityId <= 0) {
            return;
        }

        try {
            // enqueueEvent == outbox-only: no HTTP inside the save
            // transaction → merchant Create Order / Create Invoice
            // stays snappy. Cron drains within 60s.
            $this->byteClient->enqueueEvent('invoice.created', [
                'magento_entity_id' => $entityId,
                'website_id'        => $this->resolveWebsiteId($invoice),
                'store_id'          => (int) $invoice->getStoreId(),
                'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'           => [
                    'increment_id' => (string) $invoice->getIncrementId(),
                    // Hint for ledger: whether the invoice is already
                    // paid at creation (capture-online flow). Ledger
                    // uses invoice.paid webhook for the payment attach
                    // regardless, but the flag lets the dashboard
                    // surface correct expected sync outcomes.
                    'paid'         => $state === Invoice::STATE_PAID,
                ],
            ], 'invoice.created:' . $entityId, SageConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: invoice.created observer failed to enqueue for invoice ' . $entityId
                . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Invoice doesn't carry website_id directly — derive via the order.
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
