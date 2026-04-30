<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Block\Adminhtml\System\Config;

use Byte8\SageAccounting\Api\SageConfigInterface;
use Byte8\SageAccounting\Controller\Adminhtml\PairingCode\Generate;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Pairing Code" field in system config.
 *
 * Display modes:
 *   - No pending code (no stored hash)            → "Generate Pairing Code" button
 *   - Stored code exists but no session flash     → "Code pending (copy was lost — regenerate to reveal a new one)" + Regenerate button
 *   - Session flash present (just generated)      → reveal plaintext once with Copy button + countdown hint
 *
 * The flash is one-shot: consuming it here clears it from the session
 * so refreshing the page hides the code. This matches Magento's native
 * Integration Token reveal pattern.
 */
class PairingCodeField extends Field
{
    protected $_template = 'Byte8_SageAccounting::system/config/pairing_code_field.phtml';

    public function __construct(
        Context $context,
        private readonly SageConfigInterface $sageConfig,
        private readonly BackendSession $backendSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function hasPendingCode(): bool
    {
        return $this->sageConfig->getPairingCodeHash() !== null;
    }

    public function getPendingCodeAgeMinutes(): int
    {
        $issuedAt = $this->sageConfig->getPairingCodeIssuedAt();
        if ($issuedAt === null) {
            return 0;
        }
        return (int) floor((time() - $issuedAt) / 60);
    }

    public function getPendingCodeTtlMinutes(): int
    {
        return (int) ceil(SageConfigInterface::PAIRING_CODE_TTL_SECONDS / 60);
    }

    /**
     * Returns the one-shot plaintext code from session (and clears it),
     * or null if none is pending reveal.
     */
    public function consumeFlashCode(): ?string
    {
        $value = $this->backendSession->getData(Generate::SESSION_PAIRING_CODE_KEY, true);
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('byte8sageaccounting/pairingcode/generate');
    }

    public function getGenerateButtonHtml(bool $regenerate): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class)
            ->setData([
                'id' => 'byte8_sage_generate_pairing_code_button',
                'label' => $regenerate ? __('Regenerate Pairing Code') : __('Generate Pairing Code'),
                'class' => $regenerate ? 'action-secondary' : 'action-primary',
            ]);
        return $button->toHtml();
    }
}
