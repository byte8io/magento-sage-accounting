<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Block\Adminhtml\Dashboard;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Glance-able Sage sync health shown at the top of the admin dashboard.
 * Fetches once per render via ByteClient::fetchHealth() (which caches
 * for 30s) so the four indicators share a single ledger round-trip.
 *
 * Colour rules (spec §4.4):
 *   green  — connected & no errors
 *   amber  — pending_count > 0
 *   red    — failed_24h > 0 OR binding status != active
 *            OR tenant.magento_connection_status == 'token_revoked'
 *   grey   — not connected / ledger unreachable
 *
 * Click-through target is the Sage Sync iframe in the connected/warning/
 * error path. In the `token_revoked` path the iframe itself will 401
 * (ledger can't call back into Magento), so we route to the config page
 * where the merchant can regenerate the Integration Token.
 */
class HealthTile extends Template
{
    protected $_template = 'Byte8_SageAccounting::dashboard/health_tile.phtml';

    public function __construct(
        Context $context,
        private readonly SageConfigInterface $sageConfig,
        private readonly ByteClientInterface $byteClient,
        private readonly DateTime $date,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array{
     *   state:string,
     *   headline:\Magento\Framework\Phrase,
     *   last_run:string|null,
     *   pending:int|null,
     *   failed_24h:int|null,
     *   click_url:string
     * }
     */
    public function getTileData(): array
    {
        $iframeUrl = $this->getUrl('byte8sageaccounting/sync/index');
        $configUrl = $this->getUrl('adminhtml/system_config/edit', ['section' => 'byte8_sage_accounting']);

        if (!$this->sageConfig->isConnected()) {
            return [
                'state'      => 'disconnected',
                'headline'   => __('Not connected to Sage'),
                'last_run'   => null,
                'pending'    => null,
                'failed_24h' => null,
                'click_url'  => $configUrl,
            ];
        }

        $health = $this->byteClient->fetchHealth();

        if ($health === []) {
            return [
                'state'      => 'unknown',
                'headline'   => __('Ledger unreachable'),
                'last_run'   => null,
                'pending'    => null,
                'failed_24h' => null,
                'click_url'  => $iframeUrl,
            ];
        }

        $tenant = $health['tenant'] ?? [];
        $magentoStatus = (string) ($tenant['magento_connection_status'] ?? '');

        if ($magentoStatus === 'token_revoked') {
            // Iframe will 401 — bounce to config where merchant regenerates the token.
            return [
                'state'      => 'error',
                'headline'   => __('Magento disconnected — re-pair required'),
                'last_run'   => null,
                'pending'    => null,
                'failed_24h' => null,
                'click_url'  => $configUrl,
            ];
        }

        $binding = $health['binding'] ?? [];
        $sync = $health['sync'] ?? [];
        $bindingStatus = (string) ($binding['status'] ?? '');
        $region = strtoupper((string) ($binding['region'] ?? ''));

        $pending = isset($sync['pending_count']) ? (int) $sync['pending_count'] : null;
        $failed24h = isset($sync['failed_24h']) ? (int) $sync['failed_24h'] : null;
        $lastRun = isset($sync['last_run_at']) && $sync['last_run_at'] !== null
            ? (string) $sync['last_run_at']
            : null;

        if ($magentoStatus === 'pending' || $bindingStatus === '') {
            return [
                'state'      => 'warning',
                'headline'   => __('Finish setup at ledger.byte8.io'),
                'last_run'   => $lastRun,
                'pending'    => $pending,
                'failed_24h' => $failed24h,
                'click_url'  => $configUrl,
            ];
        }

        $state = 'connected';
        if ($bindingStatus !== 'active' || ($failed24h !== null && $failed24h > 0)) {
            $state = 'error';
        } elseif ($pending !== null && $pending > 0) {
            $state = 'warning';
        }

        $headline = $state === 'error' && $bindingStatus !== 'active'
            ? __('Binding %1', $bindingStatus ?: 'unknown')
            : ($region !== '' ? __('Connected to Sage (%1)', $region) : __('Connected to Sage'));

        return [
            'state'      => $state,
            'headline'   => $headline,
            'last_run'   => $lastRun,
            'pending'    => $pending,
            'failed_24h' => $failed24h,
            'click_url'  => $iframeUrl,
        ];
    }

    /**
     * Human-readable "N minutes ago" for the last_run timestamp. Falls
     * back to "No syncs yet" when null — a PR1 expected state per §4.4.
     */
    public function formatRelative(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return (string) __('No syncs yet');
        }

        $then = strtotime($iso);
        if ($then === false) {
            return (string) __('Unknown');
        }

        $now = (int) $this->date->gmtTimestamp();
        $delta = max(0, $now - $then);

        return match (true) {
            $delta < 60       => (string) __('just now'),
            $delta < 3600     => (string) __('%1 minutes ago', (int) floor($delta / 60)),
            $delta < 86400    => (string) __('%1 hours ago', (int) floor($delta / 3600)),
            default           => (string) __('%1 days ago', (int) floor($delta / 86400)),
        };
    }
}
