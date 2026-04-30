<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Test\Unit\Model\Setup;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\Client\Api\ClientConfigInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Byte8\SageAccounting\Model\Setup\Pair;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PairTest extends TestCase
{
    /** @var SageConfigInterface&MockObject */
    private SageConfigInterface $sageConfig;

    /** @var ConfigResource&MockObject */
    private ConfigResource $configResource;

    /** @var ReinitableConfigInterface&MockObject */
    private ReinitableConfigInterface $appConfig;

    /** @var TypeListInterface&MockObject */
    private TypeListInterface $cacheTypeList;

    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var EncryptorInterface&MockObject */
    private EncryptorInterface $encryptor;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private Pair $pair;

    private const PAIRING_CODE = 'deadbeefcafef00d0123456789abcdef';

    protected function setUp(): void
    {
        $this->sageConfig = $this->createMock(SageConfigInterface::class);
        $this->configResource = $this->createMock(ConfigResource::class);
        $this->appConfig = $this->createMock(ReinitableConfigInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default: valid, fresh pairing code matching our test constant.
        $this->sageConfig->method('getPairingCodeHash')
            ->willReturn(hash('sha256', self::PAIRING_CODE));
        $this->sageConfig->method('getPairingCodeIssuedAt')
            ->willReturn(time() - 10);

        $this->pair = new Pair(
            $this->sageConfig,
            $this->configResource,
            $this->appConfig,
            $this->cacheTypeList,
            $this->cache,
            $this->encryptor,
            $this->logger
        );
    }

    public function testPairPersistsAllFivePathsAndBurnsPairingCode(): void
    {
        $this->encryptor->expects(self::once())
            ->method('encrypt')
            ->with('byte8_lk_raw_secret')
            ->willReturn('<<cipher>>');

        $captured = [];
        $this->configResource->expects(self::exactly(5))
            ->method('saveConfig')
            ->willReturnCallback(
                function (string $path, $value) use (&$captured): ConfigResource {
                    $captured[$path] = $value;
                    return $this->configResource;
                }
            );

        $deletedPaths = [];
        $this->configResource->expects(self::exactly(2))
            ->method('deleteConfig')
            ->willReturnCallback(
                function (string $path) use (&$deletedPaths): ConfigResource {
                    $deletedPaths[] = $path;
                    return $this->configResource;
                }
            );

        $this->cache->expects(self::once())
            ->method('clean')
            ->with([ByteClientInterface::HEALTH_CACHE_TAG]);
        $this->appConfig->expects(self::once())->method('reinit');

        $this->pair->pair(self::PAIRING_CODE, 'tenant-123', 'byte8_lk_raw_secret', 'https://ledger.byte8.io/');

        self::assertSame('tenant-123', $captured[SageConfigInterface::XML_PATH_TENANT_ID]);
        self::assertSame('<<cipher>>', $captured[SageConfigInterface::XML_PATH_API_KEY]);
        self::assertSame('tenant-123', $captured[ClientConfigInterface::XML_PATH_TENANT_ID]);
        self::assertSame('<<cipher>>', $captured[ClientConfigInterface::XML_PATH_API_KEY]);
        self::assertSame('https://ledger.byte8.io', $captured[ClientConfigInterface::XML_PATH_BASE_URL]);

        self::assertContains(SageConfigInterface::XML_PATH_PAIRING_CODE_HASH, $deletedPaths);
        self::assertContains(SageConfigInterface::XML_PATH_PAIRING_CODE_ISSUED_AT, $deletedPaths);
    }

    public function testPairRejectsEmptyPairingCode(): void
    {
        $this->configResource->expects(self::never())->method('saveConfig');
        $this->expectException(WebapiException::class);
        $this->pair->pair('', 'tenant-1', 'secret', 'https://ledger.byte8.io');
    }

    public function testPairRejectsEmptyTenantId(): void
    {
        $this->expectException(WebapiException::class);
        $this->pair->pair(self::PAIRING_CODE, '', 'byte8_lk_x', 'https://ledger.byte8.io');
    }

    public function testPairRejectsNonUrlLedgerBase(): void
    {
        $this->expectException(WebapiException::class);
        $this->pair->pair(self::PAIRING_CODE, 'tenant-1', 'byte8_lk_x', 'not-a-url');
    }

    public function testPairRejectsWrongPairingCode(): void
    {
        $this->configResource->expects(self::never())->method('saveConfig');
        $this->expectException(WebapiException::class);
        $this->pair->pair('nottherealcode', 'tenant-1', 'secret', 'https://ledger.byte8.io');
    }

    public function testPairRejectsExpiredCode(): void
    {
        $sageConfig = $this->createMock(SageConfigInterface::class);
        $sageConfig->method('getPairingCodeHash')
            ->willReturn(hash('sha256', self::PAIRING_CODE));
        $sageConfig->method('getPairingCodeIssuedAt')
            ->willReturn(time() - 3600); // 60min > 30min TTL

        $pair = new Pair(
            $sageConfig,
            $this->configResource,
            $this->appConfig,
            $this->cacheTypeList,
            $this->cache,
            $this->encryptor,
            $this->logger
        );

        $this->configResource->expects(self::never())->method('saveConfig');
        $this->expectException(WebapiException::class);
        $pair->pair(self::PAIRING_CODE, 'tenant-1', 'secret', 'https://ledger.byte8.io');
    }

    public function testPairRejectsWhenNoCodePending(): void
    {
        $sageConfig = $this->createMock(SageConfigInterface::class);
        $sageConfig->method('getPairingCodeHash')->willReturn(null);
        $sageConfig->method('getPairingCodeIssuedAt')->willReturn(null);

        $pair = new Pair(
            $sageConfig,
            $this->configResource,
            $this->appConfig,
            $this->cacheTypeList,
            $this->cache,
            $this->encryptor,
            $this->logger
        );

        $this->expectException(WebapiException::class);
        $pair->pair(self::PAIRING_CODE, 'tenant-1', 'secret', 'https://ledger.byte8.io');
    }
}
