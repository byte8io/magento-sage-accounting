<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Model\ResourceModel\Sales;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Grid\Collection as MagentoCreditmemoGridCollection;

/**
 * Credit Memo grid collection with the byte8_entity_sync_state JOIN
 * baked in via `_initSelect()`. PR7.
 *
 * Earlier attempt used a `beforeGetSelect` plugin on Magento's
 * `Order\Creditmemo\Grid\Collection`, but `AbstractCollection::setMainTable`
 * calls `getSelect()` from inside the constructor *before* `_initSelect`
 * has populated `$this->_select` — the plugin fired against a null
 * select and crashed (`Call to a member function joinLeft() on null`).
 *
 * Subclassing + `_initSelect` override sidesteps the timing issue:
 * the JOIN lands at the canonical Magento extension hook, after the
 * parent has fully wired the select. Same approach as
 * `InvoiceGridCollection`.
 *
 * Registered as the `sales_order_creditmemo_grid_data_source` collection
 * via the `Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory`
 * override in di.xml.
 */
class CreditmemoGridCollection extends MagentoCreditmemoGridCollection
{
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->addSyncStateJoin();
        return $this;
    }

    private function addSyncStateJoin(): void
    {
        if ($this->getFlag('byte8_sync_state_joined')) {
            return;
        }
        $this->setFlag('byte8_sync_state_joined', true);

        $select = $this->getSelect();
        $connection = $this->getConnection();

        $select->joinLeft(
            ['byte8_sync' => $this->getTable(EntitySyncStateInterface::DB_TABLE_NAME)],
            sprintf(
                "byte8_sync.entity_type = %s AND byte8_sync.magento_id = main_table.entity_id AND byte8_sync.provider = %s",
                $connection->quote(EntitySyncStateInterface::ENTITY_TYPE_CREDITMEMO),
                $connection->quote(SageConfigInterface::PROVIDER_KEY)
            ),
            [
                'byte8_sync_status' => 'byte8_sync.sync_status',
                'byte8_sync_provider_entity_id' => 'byte8_sync.provider_entity_id',
                'byte8_sync_provider_reference' => 'byte8_sync.provider_reference',
                'byte8_sync_skip_reason' => 'byte8_sync.skip_reason',
                'byte8_sync_error_code' => 'byte8_sync.error_code',
                'byte8_sync_last_sync_at' => 'byte8_sync.last_sync_at',
            ]
        );
    }
}
