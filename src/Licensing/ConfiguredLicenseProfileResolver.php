<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing;

use Cbox\Billing\Licensing\Contracts\LicenseProfileResolver;
use Cbox\Billing\Licensing\ValueObjects\LicenseProfile;

/**
 * Resolves a plan id against a fixed, injected map of licensable plans. This is the
 * zero-config default the {@see LicensingServiceProvider}
 * builds from `billing.licensing.profiles`.
 *
 * Deny-by-default falls straight out of the map lookup: a plan absent from the map
 * (an unknown or non-licensable plan) resolves to `null`, so it can never be minted.
 * The default map is empty — nothing is licensable until a host declares a profile.
 */
readonly class ConfiguredLicenseProfileResolver implements LicenseProfileResolver
{
    /**
     * @param  array<string, LicenseProfile>  $profiles  keyed by plan / product id
     */
    public function __construct(
        private array $profiles = [],
    ) {}

    public function resolve(string $planId): ?LicenseProfile
    {
        return $this->profiles[$planId] ?? null;
    }
}
