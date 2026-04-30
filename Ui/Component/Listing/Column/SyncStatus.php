<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Ui\Component\Listing\Column;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the "Sage Status" chip on Sales → Orders / Invoices / Credit
 * Memos grids. PR7.
 *
 * The raw status value (`pending` / `synced` / `skipped` / `failed` /
 * empty) is joined onto each grid row by `OrderGridCollectionPlugin`
 * (and its sibling plugins for invoice / creditmemo grids). This class
 * post-processes each row to inject HTML for the chip rendering — Magento
 * UI grids render `<column>` cells via `dataType="html"` when the value
 * is wrapped in tags, which lets us emit a styled span without a JS
 * component.
 *
 * Empty / null status is rendered as `—` (no sync attempted yet — common
 * for entities that pre-date the install or sit outside the sync filters).
 */
class SyncStatus extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $field = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $status = $item[$field] ?? null;
            $tooltip = $this->buildTooltip($item);
            $item[$field] = $this->renderChip($status, $tooltip);
        }

        return $dataSource;
    }

    /**
     * Tooltip context — Sage reference if known, skip-reason or
     * error-code otherwise. Lifted out of `renderChip` so the same
     * logic is testable in isolation.
     */
    private function buildTooltip(array $item): string
    {
        if (!empty($item['byte8_sync_provider_reference'])) {
            return (string) $item['byte8_sync_provider_reference'];
        }
        if (!empty($item['byte8_sync_provider_entity_id'])) {
            return (string) $item['byte8_sync_provider_entity_id'];
        }
        if (!empty($item['byte8_sync_skip_reason'])) {
            return 'Skipped: ' . (string) $item['byte8_sync_skip_reason'];
        }
        if (!empty($item['byte8_sync_error_code'])) {
            return 'Error: ' . (string) $item['byte8_sync_error_code'];
        }
        return '';
    }

    private function renderChip(?string $status, string $tooltip): string
    {
        $tooltipAttr = $tooltip === ''
            ? ''
            : ' title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '"';

        return match ($status) {
            EntitySyncStateInterface::STATUS_SYNCED => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--synced"%s>✓ Synced</span>',
                $tooltipAttr
            ),
            EntitySyncStateInterface::STATUS_PENDING => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--pending"%s>⏳ Pending</span>',
                $tooltipAttr
            ),
            EntitySyncStateInterface::STATUS_SKIPPED => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--skipped"%s>⏸ Skipped</span>',
                $tooltipAttr
            ),
            EntitySyncStateInterface::STATUS_FAILED => sprintf(
                '<span class="byte8-sync-chip byte8-sync-chip--failed"%s>✗ Failed</span>',
                $tooltipAttr
            ),
            default => '<span class="byte8-sync-chip byte8-sync-chip--none">—</span>',
        };
    }
}
