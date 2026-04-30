<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Block\Adminhtml\SyncStatus;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Byte8\Client\Api\EntitySyncStateRepositoryInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * "Sage Accounting" admin info block on Sales → Order / Invoice /
 * Credit Memo detail pages. PR7.
 *
 * The block reads the entity from the page registry (`current_order` /
 * `current_invoice` / `current_creditmemo` — Magento's standard
 * registration pattern) and renders the chip + Sage reference + last
 * sync timestamp via the `sync_status/entity_info.phtml` template.
 *
 * Configured per-page via layout XML (one `<block>` instance per
 * adminhtml_sales_*_view layout, with `entityType` arg set so the
 * block knows which registry key to read).
 */
class EntityInfo extends Template
{
    /**
     * Map a `entity_type` arg to the layout-registry key Magento
     * populates on the corresponding detail page.
     */
    private const REGISTRY_KEYS = [
        EntitySyncStateInterface::ENTITY_TYPE_INVOICE    => 'current_invoice',
        EntitySyncStateInterface::ENTITY_TYPE_CREDITMEMO => 'current_creditmemo',
    ];

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly EntitySyncStateRepositoryInterface $syncStateRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * One of `EntitySyncStateInterface::ENTITY_TYPE_*`. Set via the
     * `entityType` argument on the layout XML `<block>`.
     */
    public function getEntityType(): string
    {
        return (string) $this->getData('entity_type');
    }

    /**
     * Resolve the current entity's sync state. Returns null if no row
     * exists yet (entity pre-dates install / outside sync_since /
     * filtered out by website/store filter).
     */
    public function getSyncState(): ?EntitySyncStateInterface
    {
        $entityType = $this->getEntityType();
        $registryKey = self::REGISTRY_KEYS[$entityType] ?? null;
        if ($registryKey === null) {
            return null;
        }

        $entity = $this->registry->registry($registryKey);
        if ($entity === null) {
            return null;
        }

        $magentoId = method_exists($entity, 'getEntityId')
            ? (int) $entity->getEntityId()
            : 0;
        if ($magentoId <= 0) {
            return null;
        }

        return $this->syncStateRepository->find(
            $entityType,
            $magentoId,
            SageConfigInterface::PROVIDER_KEY
        );
    }

    /**
     * Human-readable status label for the chip (matches the grid column
     * vocabulary).
     */
    public function getStatusLabel(?string $syncStatus): string
    {
        return match ($syncStatus) {
            EntitySyncStateInterface::STATUS_SYNCED  => __('✓ Synced')->getText(),
            EntitySyncStateInterface::STATUS_PENDING => __('⏳ Pending')->getText(),
            EntitySyncStateInterface::STATUS_SKIPPED => __('⏸ Skipped')->getText(),
            EntitySyncStateInterface::STATUS_FAILED  => __('✗ Failed')->getText(),
            default                                   => __('—')->getText(),
        };
    }

    public function getStatusCssClass(?string $syncStatus): string
    {
        return match ($syncStatus) {
            EntitySyncStateInterface::STATUS_SYNCED  => 'byte8-sync-chip byte8-sync-chip--synced',
            EntitySyncStateInterface::STATUS_PENDING => 'byte8-sync-chip byte8-sync-chip--pending',
            EntitySyncStateInterface::STATUS_SKIPPED => 'byte8-sync-chip byte8-sync-chip--skipped',
            EntitySyncStateInterface::STATUS_FAILED  => 'byte8-sync-chip byte8-sync-chip--failed',
            default                                   => 'byte8-sync-chip byte8-sync-chip--none',
        };
    }
}
