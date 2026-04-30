<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Controller\Adminhtml\PairingCode;

use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Mints a single-use pairing code used by ledger to authenticate its
 * one-time /V1/byte8/setup/pair call. The code is 128 bits of CSPRNG
 * output rendered as 32 hex chars. Stored as SHA-256(hex) + a timestamp
 * so we never keep plaintext at rest; revealed once via session flash
 * so the merchant can copy+paste into ledger.
 *
 * Clicking Generate again before the old code is consumed overwrites
 * the stored hash, invalidating the old code. 30-minute TTL enforced
 * in Model\Setup\Pair.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Byte8_SageAccounting::config';
    public const SESSION_PAIRING_CODE_KEY = 'byte8_sage_pairing_code';

    public function __construct(
        Context $context,
        private readonly ConfigResource $configResource,
        private readonly ReinitableConfigInterface $appConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly BackendSession $backendSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('adminhtml/system_config/edit', ['section' => 'byte8_sage_accounting']);

        try {
            $code = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not generate a pairing code: CSPRNG unavailable.')
            );
            return $redirect;
        }

        $hash = hash('sha256', $code);

        $this->configResource->saveConfig(
            SageConfigInterface::XML_PATH_PAIRING_CODE_HASH,
            $hash,
            'default',
            0
        );
        $this->configResource->saveConfig(
            SageConfigInterface::XML_PATH_PAIRING_CODE_ISSUED_AT,
            (string) time(),
            'default',
            0
        );

        $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
        $this->appConfig->reinit();

        $this->backendSession->setData(self::SESSION_PAIRING_CODE_KEY, $code);

        $this->messageManager->addSuccessMessage(
            __('Pairing code generated. Copy it now — it is shown once and expires in 30 minutes.')
        );
        return $redirect;
    }
}
