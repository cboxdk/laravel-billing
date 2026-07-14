<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\ValueObjects\AllowanceLease;

/**
 * The authority a node leases allowance from — billing (remote) in production, a
 * fake in tests. Leasing is PESSIMISTIC: a granted lease reserves units from the
 * organization's central budget, so the sum of outstanding leases can never
 * exceed the remaining allowance. This is what makes the local hard limit real
 * without a shared hot store.
 */
interface AllowanceLeaseSource
{
    /**
     * Lease up to `want` units of `meter` for `org`. Returns however many the
     * central budget can currently grant (0 when exhausted). The granted units
     * are reserved centrally until returned.
     */
    public function lease(string $org, string $meter, int $want): AllowanceLease;

    /**
     * Return `unused` previously-leased units to the central budget (on release,
     * commit-with-leftover, or lease expiry), making them available again.
     */
    public function giveBack(string $org, string $meter, int $unused): void;
}
