<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Enums;

/**
 * Why a request was refused as a REACHED decision (ADR-0004). Every reason here is
 * SEMANTIC — an authorization or accounting fact, never an infrastructure fault — so
 * each one fails closed (`Denied`), never `Indeterminate`. An unavailable dependency
 * is not a denial; it is {@see OutcomeStatus::Indeterminate}.
 *
 *  - `UnknownMeter`        — no policy is registered for the `(org, meter)`
 *                            (deny-by-default); the metering dimension is not trusted.
 *  - `DisabledMeter`       — a policy exists but the entitlement is disabled; refused
 *                            before any allowance/cost math so a disabled feature can
 *                            never compute zero overage and run for free.
 *  - `UnrecognizedOverage` — the resolved policy carries a `null`/unrecognized overage
 *                            behaviour, i.e. no defined answer for what to do past the
 *                            allowance. The closed {@see OverageBehaviour} enum makes
 *                            this structurally unreachable today; the reason is modelled
 *                            so any future non-enum overage source still fails closed.
 *  - `QuotaExhausted`      — the isolated allowance (under `Block`) or the leased paid
 *                            budget (under `Bill`) is exhausted — the hard limit.
 */
enum DenialReason: string
{
    case UnknownMeter = 'unknown_meter';
    case DisabledMeter = 'disabled_meter';
    case UnrecognizedOverage = 'unrecognized_overage';
    case QuotaExhausted = 'quota_exhausted';
}
