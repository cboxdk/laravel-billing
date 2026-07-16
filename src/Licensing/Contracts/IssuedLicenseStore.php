<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\Contracts;

use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;

/**
 * Where minted licenses are persisted so the issuer side can look them up to renew,
 * revoke, or answer "what does this deployment hold?".
 *
 *  - {@see find()} returns a license by its id (the artifact's `lid`), or `null`.
 *  - {@see forCustomer()} lists every license issued to a customer.
 *  - {@see forDeployment()} returns the (single) current license bound to a
 *    deployment, or `null` — one deployment holds at most one live license, so a
 *    renewal replaces it under the same deployment id.
 *
 * The in-memory default is the zero-config, dogfooded store; a host binds a
 * connection-backed implementation of the same contract for durability.
 */
interface IssuedLicenseStore
{
    public function save(IssuedLicense $license): void;

    public function find(string $id): ?IssuedLicense;

    /**
     * @return list<IssuedLicense>
     */
    public function forCustomer(string $customerId): array;

    public function forDeployment(string $deploymentId): ?IssuedLicense;
}
