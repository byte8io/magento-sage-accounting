<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\SageAccounting\Api;

/**
 * Paired-setup service (spec §4.2a).
 *
 * Ledger calls this ONCE after the merchant pastes Magento URL +
 * pairing code into `ledger.byte8.io/dashboard/connect`. It delivers:
 *   - the Byte8 master api_key (HKDF-derived outbound/inbound subkeys on both sides)
 *   - the tenant_id ledger assigned
 *   - the ledger base URL
 *
 * Authentication is the `pairing_code` in the request body, NOT a
 * bearer token. The code was generated in Magento admin via the
 * "Generate Pairing Code" button, revealed once, and pasted into the
 * ledger Connect form. It's single-use — consumed on first successful
 * call, then the stored hash is cleared. 30-minute TTL.
 *
 * See `packages/modules/module-client/SECURITY.md §"Pairing flow"`.
 *
 * Webapi route is `anonymous` — we authenticate via the pairing code
 * in the handler rather than Magento's ACL layer, so merchants don't
 * have to run the insecure `enable_integration_as_bearer` flag.
 */
interface SetupPairInterface
{
    /**
     * @param string $pairingCode   One-time code revealed in Magento admin.
     * @param string $tenantId      UUID assigned by ledger to this merchant.
     * @param string $byte8ApiKey   Master HMAC secret (HKDF-derived subkeys for each direction).
     * @param string $ledgerBaseUrl Ledger origin, e.g. https://ledger.byte8.io.
     * @return void
     */
    public function pair(
        string $pairingCode,
        string $tenantId,
        string $byte8ApiKey,
        string $ledgerBaseUrl
    ): void;
}
