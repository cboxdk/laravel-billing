<?php

declare(strict_types=1);

namespace Cbox\Billing\Events;

use Cbox\Billing\Licensing\LicenseMint;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;

/**
 * A license was minted: {@see LicenseMint::issue()} signed a license from a resolved plan
 * profile and returned the persistable record. Fires once per issued license — a
 * {@see LicenseMint::reissue()} (a renewal) runs through `issue()` and so fires this for
 * the newly-minted, independently-revocable license too.
 *
 * Carries the full {@see IssuedLicense} record (the signed `key` artifact plus the decoded
 * plan, entitlements, limits, and validity window).
 */
readonly class LicenseIssued
{
    public function __construct(
        public IssuedLicense $license,
    ) {}
}
