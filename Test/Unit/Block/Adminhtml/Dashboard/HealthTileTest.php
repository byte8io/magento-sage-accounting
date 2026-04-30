<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Test\Unit\Block\Adminhtml\Dashboard;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Byte8\SageAccounting\Block\Adminhtml\Dashboard\HealthTile;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HealthTileTest extends TestCase
{
    /** @var SageConfigInterface&MockObject */
    private SageConfigInterface $sageConfig;

    /** @var ByteClientInterface&MockObject */
    private ByteClientInterface $byteClient;

    /** @var DateTime&MockObject */
    private DateTime $date;

    /** @var UrlInterface&MockObject */
    private UrlInterface $urlBuilder;

    private HealthTile $tile;

    protected function setUp(): void
    {
        // Backend\Block\Template's constructor calls ObjectManager::getInstance()
        // to resolve JsonHelper + DirectoryHelper. In unit tests the singleton
        // isn't wired, so seed it with a permissive mock that returns stub
        // objects for any resolution. Set once and leak — PHPUnit runs tests
        // in isolation and subsequent suites re-setUp().
        $appObjectManager = $this->createMock(ObjectManagerInterface::class);
        $appObjectManager->method('get')
            ->willReturnCallback(fn (string $class): object => $this->createMock($class));
        $appObjectManager->method('create')
            ->willReturnCallback(fn (string $class): object => $this->createMock($class));
        AppObjectManager::setInstance($appObjectManager);

        $this->sageConfig = $this->createMock(SageConfigInterface::class);
        $this->byteClient = $this->createMock(ByteClientInterface::class);
        $this->date = $this->createMock(DateTime::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->urlBuilder->method('getUrl')
            ->willReturnCallback(
                static fn (string $route, array $params = []): string =>
                    'https://magento.test/admin/' . $route . (
                        $params === [] ? '' : '?' . http_build_query($params)
                    )
            );

        $context = $this->createMock(Context::class);
        $context->method('getUrlBuilder')->willReturn($this->urlBuilder);

        $objectManager = new ObjectManager($this);
        $this->tile = $objectManager->getObject(
            HealthTile::class,
            [
                'context' => $context,
                'sageConfig' => $this->sageConfig,
                'byteClient' => $this->byteClient,
                'date' => $this->date,
            ]
        );
    }

    public function testDisconnectedStateRoutesToConfigPage(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(false);
        $this->byteClient->expects(self::never())->method('fetchHealth');

        $data = $this->tile->getTileData();
        self::assertSame('disconnected', $data['state']);
        self::assertStringContainsString('system_config/edit', $data['click_url']);
        self::assertNull($data['last_run']);
        self::assertNull($data['pending']);
    }

    public function testUnknownStateWhenLedgerUnreachable(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(true);
        $this->byteClient->method('fetchHealth')->willReturn([]);

        $data = $this->tile->getTileData();
        self::assertSame('unknown', $data['state']);
        self::assertStringContainsString('byte8sageaccounting/sync/index', $data['click_url']);
    }

    public function testTokenRevokedRoutesToConfigNotIframe(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(true);
        $this->byteClient->method('fetchHealth')->willReturn([
            'tenant' => ['magento_connection_status' => 'token_revoked'],
            'binding' => ['status' => 'active', 'region' => 'uk'],
            'sync' => ['pending_count' => 0, 'failed_24h' => 0],
        ]);

        $data = $this->tile->getTileData();
        self::assertSame('error', $data['state']);
        // Iframe would 401 on revoked token — click-through must go to config.
        self::assertStringContainsString('system_config/edit', $data['click_url']);
        self::assertStringNotContainsString('byte8sageaccounting/sync/index', $data['click_url']);
    }

    public function testPendingStateWhenMagentoPairedButSageNotYetConnected(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(true);
        $this->byteClient->method('fetchHealth')->willReturn([
            'tenant' => ['magento_connection_status' => 'pending'],
            'binding' => [],
            'sync' => [],
        ]);

        $data = $this->tile->getTileData();
        self::assertSame('warning', $data['state']);
        self::assertStringContainsString('system_config/edit', $data['click_url']);
    }

    public function testConnectedStateRendersRegionAndIframeClickThrough(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(true);
        $this->byteClient->method('fetchHealth')->willReturn([
            'tenant'  => ['magento_connection_status' => 'active'],
            'binding' => ['status' => 'active', 'region' => 'uk'],
            'sync'    => [
                'last_run_at'    => '2026-04-20T12:00:00Z',
                'pending_count'  => 0,
                'failed_24h'     => 0,
            ],
        ]);
        $this->date->method('gmtTimestamp')->willReturn(strtotime('2026-04-20T12:05:00Z'));

        $data = $this->tile->getTileData();
        self::assertSame('connected', $data['state']);
        self::assertStringContainsString('(UK)', (string) $data['headline']);
        self::assertStringContainsString('byte8sageaccounting/sync/index', $data['click_url']);
        self::assertSame(0, $data['pending']);
        self::assertSame(0, $data['failed_24h']);
    }

    public function testErrorStateWhenFailed24hGreaterThanZero(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(true);
        $this->byteClient->method('fetchHealth')->willReturn([
            'tenant'  => ['magento_connection_status' => 'active'],
            'binding' => ['status' => 'active', 'region' => 'gb'],
            'sync'    => ['pending_count' => 0, 'failed_24h' => 3],
        ]);

        $data = $this->tile->getTileData();
        self::assertSame('error', $data['state']);
    }

    public function testWarningStateWhenPendingGreaterThanZero(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(true);
        $this->byteClient->method('fetchHealth')->willReturn([
            'tenant'  => ['magento_connection_status' => 'active'],
            'binding' => ['status' => 'active', 'region' => 'uk'],
            'sync'    => ['pending_count' => 5, 'failed_24h' => 0],
        ]);

        $data = $this->tile->getTileData();
        self::assertSame('warning', $data['state']);
        self::assertSame(5, $data['pending']);
    }

    public function testErrorStateWhenBindingNotActive(): void
    {
        $this->sageConfig->method('isConnected')->willReturn(true);
        $this->byteClient->method('fetchHealth')->willReturn([
            'tenant'  => ['magento_connection_status' => 'active'],
            'binding' => ['status' => 'revoked', 'region' => 'uk'],
            'sync'    => ['pending_count' => 0, 'failed_24h' => 0],
        ]);

        $data = $this->tile->getTileData();
        self::assertSame('error', $data['state']);
        self::assertStringContainsString('revoked', (string) $data['headline']);
    }

    public function testFormatRelativeHandlesNullAsNoSyncsYet(): void
    {
        self::assertSame('No syncs yet', $this->tile->formatRelative(null));
        self::assertSame('No syncs yet', $this->tile->formatRelative(''));
    }

    public function testFormatRelativeProducesMinutesWindow(): void
    {
        $this->date->method('gmtTimestamp')->willReturn(strtotime('2026-04-20T12:05:00Z'));
        self::assertSame('5 minutes ago', $this->tile->formatRelative('2026-04-20T12:00:00Z'));
    }
}
