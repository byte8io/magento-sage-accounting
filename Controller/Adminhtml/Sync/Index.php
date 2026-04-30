<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Controller\Adminhtml\Sync;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Renders the Byte8 → Sage Sync admin page — a full-page iframe to the
 * ledger embed UI (see LEDGER_INTEGRATION_SPEC §4.3). The actual
 * rendering happens entirely server-side on ledger.byte8.io; this
 * controller only hosts the frame and sets a tight CSP so the iframe
 * can render inside Magento admin without opening the page to XFO.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = 'Byte8_SageAccounting::sync';

    public function __construct(
        Context $context,
        private readonly SageConfigInterface $sageConfig,
        private readonly ClientConfigInterface $clientConfig
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        if (!$this->sageConfig->isConnected()) {
            /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $this->messageManager->addNoticeMessage(
                __('Connect to Sage before opening Sage Sync — there is nothing to show yet.')
            );
            return $redirect->setPath('adminhtml/system_config/edit', ['section' => 'byte8_sage_accounting']);
        }

        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Byte8_SageAccounting::sync');
        $resultPage->getConfig()->getTitle()->prepend(__('Sage Sync'));

        // Allow the ledger embed host to frame this page back (required for
        // the iframe to render without X-Frame-Options blocking it). The
        // ledger side will match this host when setting its own frame
        // ancestors header — see spec §4.3.
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHeader(
            'Content-Security-Policy',
            "frame-src " . $this->clientConfig->getBaseUrl() . ";",
            true
        );

        return $resultPage;
    }
}
