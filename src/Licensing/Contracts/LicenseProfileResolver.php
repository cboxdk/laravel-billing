<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\Contracts;

use Cbox\Billing\Licensing\ValueObjects\LicenseProfile;

/**
 * Resolves a plan / product id to the {@see LicenseProfile} it grants, or `null` when
 * the id is unknown or not licensable.
 *
 * **Deny-by-default:** a plan with no registered profile resolves to `null`, and a
 * `null` profile cannot be minted. Only plans a host has explicitly declared
 * licensable ever produce a license — an on-prem / enterprise plan, never a
 * self-serve one that ships no offline artifact.
 */
interface LicenseProfileResolver
{
    public function resolve(string $planId): ?LicenseProfile;
}
