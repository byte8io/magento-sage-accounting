<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Model\Setup;

use Byte8\Client\Api\ByteClientInterface;
use Byte8\Client\Api\ClientConfigInterface;
use Byte8\SageAccounting\Api\SageConfigInterface;
use Byte8\SageAccounting\Api\SetupPairInterface;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Psr\Log\LoggerInterface;

/**
 * Validates the pairing code and persists the delivered master api_key
 * + tenant_id + ledger base URL. Single-use: on success the stored
 * pairing-code hash and timestamp are deleted so the same code cannot
 * be replayed, even within its 30-minute window.
 *
 * Intentionally NOT using bearer-token auth — the route is marked
 * `anonymous` in webapi.xml and auth is enforced here via constant-time
 * comparison of SHA-256(pairing_code) against the stored hash.
 */
class Pair implements SetupPairInterface
{
    public function __construct(
        private readonly SageConfigInterface $sageConfig,
        private readonly ConfigResource $configResource,
        private readonly ReinitableConfigInterface $appConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CacheInterface $cache,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function pair(
        string $pairingCode,
        string $tenantId,
        string $byte8ApiKey,
        string $ledgerBaseUrl
    ): void {
        $pairingCode = trim($pairingCode);
        $tenantId = trim($tenantId);
        $byte8ApiKey = trim($byte8ApiKey);
        $ledgerBaseUrl = rtrim(trim($ledgerBaseUrl), '/');

        $this->assertInputsPresent($pairingCode, $tenantId, $byte8ApiKey, $ledgerBaseUrl);
        $this->assertPairingCode($pairingCode);

        $encryptedKey = $this->encryptor->encrypt($byte8ApiKey);

        // Lock-step writes: the sage-accounting copy is what the module reads
        // for display + Disconnect, the client copy is what ByteClient /
        // KeyDerivation / Signer / Verifier read. Writing one without the
        // other leaves the install in a half-paired state.
        $this->configResource->saveConfig(SageConfigInterface::XML_PATH_TENANT_ID, $tenantId, 'default', 0);
        $this->configResource->saveConfig(SageConfigInterface::XML_PATH_API_KEY, $encryptedKey, 'default', 0);
        $this->configResource->saveConfig(ClientConfigInterface::XML_PATH_TENANT_ID, $tenantId, 'default', 0);
        $this->configResource->saveConfig(ClientConfigInterface::XML_PATH_API_KEY, $encryptedKey, 'default', 0);
        $this->configResource->saveConfig(ClientConfigInterface::XML_PATH_BASE_URL, $ledgerBaseUrl, 'default', 0);

        // Single-use: burn the pairing code so it can't be replayed.
        $this->configResource->deleteConfig(SageConfigInterface::XML_PATH_PAIRING_CODE_HASH, 'default', 0);
        $this->configResource->deleteConfig(SageConfigInterface::XML_PATH_PAIRING_CODE_ISSUED_AT, 'default', 0);

        $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
        $this->cache->clean([ByteClientInterface::HEALTH_CACHE_TAG]);
        $this->appConfig->reinit();

        $this->logger->info(
            'Byte8: paired successfully for tenant ' . $tenantId . ' — outbound + inbound keys live'
        );
    }

    private function assertInputsPresent(
        string $pairingCode,
        string $tenantId,
        string $byte8ApiKey,
        string $ledgerBaseUrl
    ): void {
        if ($pairingCode === '' || $tenantId === '' || $byte8ApiKey === '' || $ledgerBaseUrl === '') {
            throw new WebapiException(
                __('pairing_code, tenant_id, byte8_api_key and ledger_base_url are all required.'),
                0,
                WebapiException::HTTP_BAD_REQUEST
            );
        }
        if (!preg_match('#^https?://[^/]+#', $ledgerBaseUrl)) {
            throw new WebapiException(
                __('ledger_base_url must be an absolute http(s) URL.'),
                0,
                WebapiException::HTTP_BAD_REQUEST
            );
        }
    }

    private function assertPairingCode(string $pairingCode): void
    {
        $storedHash = $this->sageConfig->getPairingCodeHash();
        $issuedAt = $this->sageConfig->getPairingCodeIssuedAt();

        if ($storedHash === null || $issuedAt === null) {
            $this->logger->warning(
                'Byte8: /setup/pair rejected — no pairing code is pending generation'
            );
            // Generic 401 — never reveal whether a code was expected or not.
            throw $this->unauthorized();
        }

        if ((time() - $issuedAt) > SageConfigInterface::PAIRING_CODE_TTL_SECONDS) {
            $this->logger->warning(
                'Byte8: /setup/pair rejected — pairing code expired (age exceeds 30min TTL)'
            );
            throw $this->unauthorized();
        }

        if (!hash_equals($storedHash, hash('sha256', $pairingCode))) {
            $this->logger->warning('Byte8: /setup/pair rejected — pairing code mismatch');
            throw $this->unauthorized();
        }
    }

    private function unauthorized(): WebapiException
    {
        // Intentionally terse — attackers should not learn whether the
        // code is missing, expired, or wrong. The Magento log tells the
        // merchant the real reason.
        return new WebapiException(
            __('Invalid or expired pairing code.'),
            0,
            WebapiException::HTTP_UNAUTHORIZED
        );
    }
}
