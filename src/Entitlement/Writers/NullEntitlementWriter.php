<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Writers;

use Cbox\Billing\Entitlement\Contracts\EntitlementWriter;
use Cbox\Billing\Entitlement\ValueObjects\Entitlement;

/**
 * The default writer: does nothing. The host binds a real adapter onto identity
 * (cbox-id) to actually project entitlements.
 */
readonly class NullEntitlementWriter implements EntitlementWriter
{
    public function set(Entitlement $entitlement): void {}

    public function revoke(string $organizationId, string $sourceRef): void {}
}
