<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Model\ResourceModel\Sales;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;

/**
 * Invoice grid collection with the byte8_entity_sync_state JOIN baked in.
 * PR7.
 *
 * Replaces Magento's stock virtualType
 * `Magento\Sales\Model\ResourceModel\Order\Invoice\Grid\Collection`
 * via di.xml — that virtualType is a bare `SearchResult` subclass with
 * no real PHP file, so plugins on it can't generate an Interceptor
 * (`ReflectionException: Class … \Interceptor does not exist`). The
 * subclass approach is the canonical Magento way to add JOINs to
 * grid data sources keyed by virtualType. The Credit Memo grid uses
 * a real concrete class (`Magento\Sales\Model\ResourceModel\Order\Creditmemo\Grid\Collection`)
 * so it stays on the plugin path — see SyncStateJoinPlugin.
 */
class InvoiceGridCollection extends SearchResult
{
    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        $mainTable = 'sales_invoice_grid',
        $resourceModel = \Magento\Sales\Model\ResourceModel\Order\Invoice::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    /**
     * Select hook — JOIN the mirror table so the grid's "Sage Status"
     * column has values to render. LEFT JOIN keeps every invoice row
     * visible even when no mirror entry exists yet (pre-install
     * invoices, or rows in flight before the cron drain). The composite
     * UNIQUE index `(entity_type, magento_id, provider)` makes this
     * sub-millisecond per row at every realistic page size — see
     * `MAGENTO_THIN_MODULE.md` "Read-path performance".
     */
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
                $connection->quote(EntitySyncStateInterface::ENTITY_TYPE_INVOICE),
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
