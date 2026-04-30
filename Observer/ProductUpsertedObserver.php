<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Observer;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes `product.upserted` to ledger on every catalog product save
 * AND every stock-item save. Reusing one event for both surfaces means
 * ledger doesn't need a second JobKind for stock changes — the worker
 * always re-fetches the canonical product (which carries both catalog
 * metadata AND `stock_qty` / `manage_stock`) and decides on the Sage
 * side whether to upsert the catalog row, post a /stock_movements
 * reconciliation, or both. Idempotent: ledger dedupes via
 * `Idempotency-Key` (`product.upserted:{id}`) so two events from the
 * same logical save (catalog + stock) collapse to one Sage round-trip.
 *
 * Wired to two Magento events:
 *   - `catalog_product_save_after`        — catalog edits (name,
 *                                            price, type, etc.)
 *   - `cataloginventory_stock_item_save_after` — stock movements
 *                                            (admin manual edits;
 *                                             sales auto-decrement
 *                                             also fires this).
 *
 * Both events are entity-id-only on the wire — ledger refetches via
 * `GET /V1/byte8/product/:id`. We extract the product id from
 * whichever shape the event carries and short-circuit when it's
 * absent.
 */
class ProductUpsertedObserver implements ObserverInterface
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

        $entityId = $this->extractProductId($observer);
        if ($entityId === null) {
            return;
        }

        try {
            $this->byteClient->enqueueEvent('product.upserted', [
                'magento_entity_id' => $entityId,
                // Products live across multiple websites in Magento;
                // the canonical fetched by ledger carries the full
                // website_ids list, so we just emit 0 here.
                'website_id' => 0,
                'store_id'   => 0,
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'payload'     => (object) [],
            ], 'product.upserted:' . $entityId, SageConfigInterface::PROVIDER_KEY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Byte8: product.upserted observer failed to enqueue for product ' . $entityId
                . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Pull the product id out of either event shape:
     *   - catalog_product_save_after passes the saved entity under
     *     `product` (ProductInterface or ProductModel).
     *   - cataloginventory_stock_item_save_after passes a
     *     StockItemInterface under `item`; its product id is on
     *     `getProductId()`.
     *
     * Returns null when neither shape is present (defensive: third-
     * party modules sometimes dispatch these events with custom
     * payloads).
     */
    private function extractProductId(Observer $observer): ?int
    {
        $event = $observer->getEvent();

        // catalog_product_save_after path
        $product = $event->getData('product');
        if ($product instanceof ProductInterface || $product instanceof ProductModel) {
            $id = (int) $product->getId();
            return $id > 0 ? $id : null;
        }

        // cataloginventory_stock_item_save_after path
        $stockItem = $event->getData('item');
        if ($stockItem instanceof StockItemInterface) {
            $id = (int) $stockItem->getProductId();
            return $id > 0 ? $id : null;
        }

        return null;
    }
}
