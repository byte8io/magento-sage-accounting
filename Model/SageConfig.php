<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Model;

use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class SageConfig implements SageConfigInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function getTenantId(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_TENANT_ID);
        return $value ? (string) $value : null;
    }

    public function getApiKey(): ?string
    {
        $cipher = (string) $this->scopeConfig->getValue(self::XML_PATH_API_KEY);
        if ($cipher === '') {
            return null;
        }
        $plain = $this->encryptor->decrypt($cipher);
        return $plain !== '' ? $plain : null;
    }

    public function getPairingCodeHash(): ?string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_PAIRING_CODE_HASH);
        return $value !== '' ? $value : null;
    }

    public function getPairingCodeIssuedAt(): ?int
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PAIRING_CODE_ISSUED_AT);
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function isConnected(): bool
    {
        return $this->getTenantId() !== null && $this->getApiKey() !== null;
    }
}
