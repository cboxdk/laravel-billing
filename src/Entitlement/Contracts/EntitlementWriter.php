<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Contracts;

use Cbox\Billing\Entitlement\ValueObjects\Entitlement;

/**
 * The port billing writes coarse entitlements through — implemented by an adapter
 * onto the identity platform (cbox-id's EntitlementWriter, source = Billing). The
 * billing package stays decoupled from identity; the host wires the adapter.
 *
 * Scoping is per (organization, product), keyed by `sourceRef` (the subscription):
 * `set` is an UPSERT of one product's entitlement and MUST NOT disturb the org's
 * other products; `revoke` removes only the entitlement for that `sourceRef`. An
 * organization holds many concurrent product entitlements.
 */
interface EntitlementWriter
{
    public function set(Entitlement $entitlement): void;

    public function revoke(string $organizationId, string $sourceRef): void;
}
