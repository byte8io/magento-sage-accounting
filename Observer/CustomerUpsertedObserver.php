<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Observer;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer as CustomerModel;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes `customer.upserted` on `customer_save_after`. Same id-only
 * shape as invoice.paid; ledger refetches canonical data via
 * `GET /V1/byte8/customer/:id` once the save transaction commits.
 *
 * The `customer_save_after` event passes the saved entity under the
 * `customer` (Model) or `customer_data_object` (DataObject) event key
 * depending on which code path reached the save — handle both.
 */
class CustomerUpsertedObserver implements ObserverInterface
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

        $entityId = $this->extractCustomerId($observer);
        if ($entityId === null) {
            return;
        }

        $websiteId = $this->extractWebsiteId($observer);

        try {
            $this->byteClient->enqueueEvent('customer.upserted', [
                'magento_entity_id' => $entityId,
                'website_id'        => $websiteId,
                'store_id'          => 0, // customers aren't store-scoped in Magento; omit via 0
                'occurred_at'       => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'           => (object) [],
            ], 'customer.upserted:' . $entityId, SageConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: customer.upserted observer failed to enqueue for customer ' . $entityId
                . ': ' . $e->getMessage()
            );
        }
    }

    private function extractCustomerId(Observer $observer): ?int
    {
        $event = $observer->getEvent();
        $candidate = $event->getData('customer_data_object') ?? $event->getData('customer');
        if ($candidate instanceof CustomerInterface || $candidate instanceof CustomerModel) {
            $id = (int) $candidate->getId();
            return $id > 0 ? $id : null;
        }
        return null;
    }

    private function extractWebsiteId(Observer $observer): int
    {
        $event = $observer->getEvent();
        $candidate = $event->getData('customer_data_object') ?? $event->getData('customer');
        if ($candidate instanceof CustomerInterface && $candidate->getWebsiteId() !== null) {
            return (int) $candidate->getWebsiteId();
        }
        if ($candidate instanceof CustomerModel) {
            return (int) $candidate->getWebsiteId();
        }
        return 0;
    }
}
