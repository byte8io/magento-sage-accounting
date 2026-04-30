<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Test\Unit\Model;

use Byte8\SageAccounting\Api\SageConfigInterface;
use Byte8\SageAccounting\Model\SageConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SageConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var EncryptorInterface&MockObject */
    private EncryptorInterface $encryptor;

    private SageConfig $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->config = new SageConfig($this->scopeConfig, $this->encryptor);
    }

    public function testGetTenantIdReturnsNullWhenConfigEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(SageConfigInterface::XML_PATH_TENANT_ID)
            ->willReturn(null);

        self::assertNull($this->config->getTenantId());
    }

    public function testGetTenantIdReturnsStringWhenSet(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(SageConfigInterface::XML_PATH_TENANT_ID)
            ->willReturn('a8d4e2e0-1c4e-11ef-9262-0242ac120002');

        self::assertSame('a8d4e2e0-1c4e-11ef-9262-0242ac120002', $this->config->getTenantId());
    }

    public function testGetApiKeyDecryptsStoredCipher(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(SageConfigInterface::XML_PATH_API_KEY)
            ->willReturn('<<encrypted-cipher>>');
        $this->encryptor->expects(self::once())
            ->method('decrypt')
            ->with('<<encrypted-cipher>>')
            ->willReturn('byte8_lk_decoded_secret');

        self::assertSame('byte8_lk_decoded_secret', $this->config->getApiKey());
    }

    public function testGetApiKeyReturnsNullWhenCipherEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(SageConfigInterface::XML_PATH_API_KEY)
            ->willReturn('');
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertNull($this->config->getApiKey());
    }

    public function testGetApiKeyReturnsNullWhenDecryptYieldsEmptyString(): void
    {
        // Magento's encryptor returns '' when a crypto key rotation leaves the
        // ciphertext undecryptable — treat as "no key" rather than a valid key.
        $this->scopeConfig->method('getValue')
            ->with(SageConfigInterface::XML_PATH_API_KEY)
            ->willReturn('<<stale-cipher>>');
        $this->encryptor->method('decrypt')->willReturn('');

        self::assertNull($this->config->getApiKey());
    }

    public function testIsConnectedRequiresBothTenantAndKey(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => match ($path) {
                SageConfigInterface::XML_PATH_TENANT_ID => 'tenant-1',
                SageConfigInterface::XML_PATH_API_KEY   => '<<cipher>>',
                default                                 => null,
            }
        );
        $this->encryptor->method('decrypt')->with('<<cipher>>')->willReturn('byte8_lk_x');

        self::assertTrue($this->config->isConnected());
    }

    public function testIsConnectedFalseWhenApiKeyMissing(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => match ($path) {
                SageConfigInterface::XML_PATH_TENANT_ID => 'tenant-1',
                SageConfigInterface::XML_PATH_API_KEY   => '',
                default                                 => null,
            }
        );

        self::assertFalse($this->config->isConnected());
    }

    public function testIsConnectedFalseWhenTenantIdMissing(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => match ($path) {
                SageConfigInterface::XML_PATH_TENANT_ID => null,
                SageConfigInterface::XML_PATH_API_KEY   => '<<cipher>>',
                default                                 => null,
            }
        );
        $this->encryptor->method('decrypt')->willReturn('byte8_lk_x');

        self::assertFalse($this->config->isConnected());
    }
}
