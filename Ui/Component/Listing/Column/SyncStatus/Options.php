<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Ui\Component\Listing\Column\SyncStatus;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Filter dropdown options for the "Sage Status" column. PR7.
 */
class Options implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => EntitySyncStateInterface::STATUS_SYNCED,  'label' => __('Synced')],
            ['value' => EntitySyncStateInterface::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => EntitySyncStateInterface::STATUS_SKIPPED, 'label' => __('Skipped')],
            ['value' => EntitySyncStateInterface::STATUS_FAILED,  'label' => __('Failed')],
        ];
    }
}
