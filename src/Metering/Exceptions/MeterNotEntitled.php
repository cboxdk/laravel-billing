<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Exceptions;

use Cbox\Billing\Metering\Enums\DenialReason;
use Cbox\Billing\Metering\Enums\OutcomeStatus;
use RuntimeException;

/**
 * A SEMANTIC refusal (distinct from {@see QuotaExceeded}, which is an exhausted
 * allowance/spend): the `(org, meter)` bucket is not entitled — either no policy is
 * registered for it (unknown → deny-by-default) or the entitlement is disabled. This
 * is checked FIRST, before any allowance/cost math, so a disabled feature can never
 * compute zero overage and run for free.
 *
 * Per ADR-0004 semantic unknowns fail closed. The refusal folds into the enforcement
 * outcome type as {@see OutcomeStatus::Denied}: the
 * `denialReason` it carries is the authoritative semantic classification, decided here
 * at the throw site rather than re-derived from the message downstream.
 */
class MeterNotEntitled extends RuntimeException
{
    public function __construct(
        public readonly string $org,
        public readonly string $meter,
        public readonly string $reason,
        public readonly DenialReason $denialReason,
    ) {
        parent::__construct("Meter [{$meter}] is not entitled for organization [{$org}]: {$reason}.");
    }

    public static function unknown(string $org, string $meter): self
    {
        return new self($org, $meter, 'no policy is registered for this meter', DenialReason::UnknownMeter);
    }

    public static function disabled(string $org, string $meter): self
    {
        return new self($org, $meter, 'the feature is disabled for this organization', DenialReason::DisabledMeter);
    }
}
