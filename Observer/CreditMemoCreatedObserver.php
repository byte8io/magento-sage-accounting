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
use Magento\Sales\Api\Data\CreditmemoInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes `creditmemo.created` on `sales_order_creditmemo_save_after`.
 * Id-only payload per spec §4.2 — ledger's worker fetches the canonical
 * credit memo via `GET /V1/byte8/creditmemo/:id` once the transaction
 * commits.
 *
 * Magento fires `sales_order_creditmemo_save_after` on every save —
 * including state transitions like "pending" → "refunded". For a first
 * cut we publish on every save and rely on ledger's `entity_xref` dedup
 * to short-circuit repeated posts. If that proves noisy in production
 * we can gate on `state === STATE_REFUNDED` here.
 */
class CreditMemoCreatedObserver implements ObserverInterface
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

        $memo = $observer->getEvent()->getData('creditmemo');
        if (!$memo instanceof CreditmemoInterface) {
            return;
        }

        $entityId = (int) $memo->getEntityId();
        if ($entityId <= 0) {
            return;
        }

        try {
            $this->byteClient->enqueueEvent('creditmemo.created', [
                'magento_entity_id' => $entityId,
                'website_id'        => $this->resolveWebsiteId($memo),
                'store_id'          => (int) $memo->getStoreId(),
                'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'           => [
                    'increment_id' => (string) $memo->getIncrementId(),
                ],
            ], 'creditmemo.created:' . $entityId, SageConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: creditmemo.created observer failed to enqueue for memo ' . $entityId
                . ': ' . $e->getMessage()
            );
        }
    }

    private function resolveWebsiteId(CreditmemoInterface $memo): int
    {
        if (method_exists($memo, 'getOrder')) {
            $order = $memo->getOrder();
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
