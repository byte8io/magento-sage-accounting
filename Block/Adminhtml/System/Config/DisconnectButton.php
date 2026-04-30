<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Block\Adminhtml\System\Config;

use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the Disconnect button. Hidden while not connected — nothing
 * to disconnect from.
 */
class DisconnectButton extends Field
{
    protected $_template = 'Byte8_SageAccounting::system/config/disconnect_button.phtml';

    public function __construct(
        Context $context,
        private readonly SageConfigInterface $sageConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getDisconnectUrl(): string
    {
        return $this->getUrl('byte8sageaccounting/connection/disconnect');
    }

    public function isConnected(): bool
    {
        return $this->sageConfig->isConnected();
    }

    public function getButtonHtml(): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class)
            ->setData([
                'id' => 'byte8_sage_disconnect_button',
                'label' => __('Disconnect'),
                'class' => 'action-secondary',
                'disabled' => !$this->isConnected(),
            ]);
        return $button->toHtml();
    }
}
