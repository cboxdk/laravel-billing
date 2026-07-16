<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\ValueObjects;

use Cbox\Billing\Licensing\Contracts\LicenseProfileResolver;
use Cbox\License\Capabilities;
use Cbox\License\ValueObjects\LicenseLimits;

/**
 * What a licensable plan grants: the plan id carried into the license, the opaque
 * entitlement strings ({@see Capabilities}) it unlocks, and the quantitative
 * {@see LicenseLimits} it ceilings. This is the issuer-side policy — the mapping
 * from a paid plan to a license's contents lives here, never in the verifier
 * (which treats entitlements as opaque data).
 *
 * A profile is what the {@see LicenseProfileResolver}
 * returns for a licensable plan; an unknown / non-licensable plan resolves to `null`
 * (deny-by-default) and therefore cannot be minted.
 */
readonly class LicenseProfile
{
    /**
     * @param  list<string>  $entitlements  Opaque capability strings (see {@see Capabilities}).
     */
    public function __construct(
        public string $plan,
        public array $entitlements,
        public LicenseLimits $limits,
    ) {}
}
