<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Displays the merchant-facing Magento base URL so they can copy it
 * into the Connect Store form at ledger.byte8.io. Read-only — ledger
 * needs to reach Magento at this URL for the paired-setup callback and
 * ongoing canonical REST fetches, so it must match what's on the
 * public internet (not `localhost` or an internal hostname).
 */
class MagentoBaseUrl extends Field
{
    protected $_template = 'Byte8_SageAccounting::system/config/magento_base_url.phtml';

    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getMagentoBaseUrl(): string
    {
        // Public storefront base URL — the host ledger should call
        // against. Storefront base, not admin base: ledger hits
        // /rest/V1/byte8/* which is public-routable.
        return rtrim(
            $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true),
            '/'
        );
    }
}
