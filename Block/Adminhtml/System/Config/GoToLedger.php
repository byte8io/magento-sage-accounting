<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Block\Adminhtml\System\Config;

use Byte8\Client\Api\ClientConfigInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Go to ledger.byte8.io →" deep link. If the merchant has no Byte8
 * session yet, ledger handles the sign-up redirect and bounces them
 * back to /dashboard post-signup.
 */
class GoToLedger extends Field
{
    private const LEDGER_DASHBOARD_PATH = '/dashboard';

    protected $_template = 'Byte8_SageAccounting::system/config/go_to_ledger.phtml';

    public function __construct(
        Context $context,
        private readonly ClientConfigInterface $clientConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getLedgerDashboardUrl(): string
    {
        $base = $this->clientConfig->getBaseUrl();
        if ($base === '') {
            $base = 'https://ledger.byte8.io';
        }
        return $base . self::LEDGER_DASHBOARD_PATH;
    }

    public function getButtonHtml(): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class)
            ->setData([
                'id' => 'byte8_sage_go_to_ledger_button',
                'label' => __('Open ledger.byte8.io →'),
                'class' => 'action-primary',
            ]);
        return $button->toHtml();
    }
}
