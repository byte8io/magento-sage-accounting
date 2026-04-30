<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Block\Adminhtml\Sync;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Iframe extends Template
{
    protected $_template = 'Byte8_SageAccounting::sync/iframe.phtml';

    public function __construct(
        Context $context,
        private readonly SageConfigInterface $sageConfig,
        private readonly ClientConfigInterface $clientConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEmbedUrl(): string
    {
        $tenantId = (string) $this->sageConfig->getTenantId();
        if ($tenantId === '') {
            return '';
        }
        return sprintf(
            '%s/embed/%s',
            $this->clientConfig->getBaseUrl(),
            rawurlencode($tenantId)
        );
    }
}
