<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing;

use DateInterval;
use DateTimeImmutable;

/**
 * Derives a license's `expiresAt` from the subscription's current paid-period end so
 * a renewal's reissue tracks the paid window. A configurable grace buffer is added on
 * top of the period end, so the offline artifact stays valid slightly past the
 * boundary — covering the lag between a renewal being paid and the new license being
 * pulled by the deployment, without ever handing out a license that outlives the paid
 * period by more than the operator's chosen buffer.
 *
 * This is a small pure helper: it computes a date, nothing else. The app decides WHEN
 * to renew and calls {@see LicenseMint::reissue()} with the result.
 */
readonly class SubscriptionLicensePolicy
{
    public function __construct(
        private int $graceSeconds = 0,
    ) {}

    /**
     * The license `expiresAt` for a plan whose current paid period ends at
     * `$currentPeriodEnd`: the period end plus the configured grace buffer.
     */
    public function expiresAtFor(DateTimeImmutable $currentPeriodEnd): DateTimeImmutable
    {
        if ($this->graceSeconds <= 0) {
            return $currentPeriodEnd;
        }

        return $currentPeriodEnd->add(new DateInterval('PT'.$this->graceSeconds.'S'));
    }
}
