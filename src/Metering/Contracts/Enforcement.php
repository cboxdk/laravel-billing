<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\Exceptions\MeterNotEntitled;
use Cbox\Billing\Metering\Exceptions\QuotaExceeded;
use Cbox\Billing\Metering\ValueObjects\BucketRequest;
use Cbox\Billing\Metering\ValueObjects\EnforcementOutcome;
use Cbox\Billing\Metering\ValueObjects\Reservation;
use Cbox\Billing\Metering\ValueObjects\ReservationSet;

/**
 * The app-local hot path — the SDK-facing enforcement API. It runs inside the
 * consuming app (in-memory or the app's own store), never against a shared/
 * co-located billing Redis. It enforces a HARD limit against a leased slice of
 * the organization's remaining allowance (see {@see AllowanceLeaseSource}); when
 * the local lease is depleted it requests a refill. Because leasing is pessimistic
 * (a lease reserves units from the central budget), the org can never exceed its
 * allowance — the only "drift" is leased-but-unused units temporarily stranded on
 * a node, plus reporting lag. Billing remains the eventual authority.
 */
interface Enforcement
{
    /**
     * Atomically hold `estimate` units for `meter` on `org` against the local
     * lease, refilling from the lease source if needed.
     *
     * @throws QuotaExceeded when the organization's remaining allowance is
     *                       exhausted (the hard limit)
     */
    public function reserve(string $org, string $meter, int $estimate): Reservation;

    /**
     * Settle a reservation to the actual amount used (must be <= the reserved
     * estimate). The unused difference returns to the local lease, and a durable
     * usage event is appended for later sync to billing.
     */
    public function commit(Reservation $reservation, int $actual): void;

    /** Release a held reservation without charging (error paths). */
    public function release(Reservation $reservation): void;

    /**
     * Locally-known remaining balance for `meter` on `org` — for UX/pre-checks
     * only. It may lag billing by up to one drift window; never use it for
     * accounting.
     */
    public function balance(string $org, string $meter): int;

    /**
     * Reserve a SET of buckets for `org` in one call — one independent bucket per
     * meter — evaluating each against its own {@see MeterPolicy} and never collapsing
     * them into a single number. Per bucket, in order: entitlement `enabled?` FIRST
     * (a disabled/unknown meter is refused before any allowance or cost math), then
     * the isolated allowance (an atomic disjoint-slice claim), then the weighted
     * overage cost, then the overage behaviour. The reservation is all-or-nothing:
     * if any bucket is refused, every claimed slice and leased unit is rolled back.
     *
     * The single-meter {@see reserve()} is the degenerate case of a set of one.
     *
     * @param  list<BucketRequest>  $requests
     *
     * @throws MeterNotEntitled when a bucket is unknown (deny-by-default) or disabled
     * @throws QuotaExceeded when a bucket's allowance is exhausted under `Block`, or
     *                       the leased paid budget is exhausted under `Bill`
     */
    public function reserveBuckets(string $org, array $requests): ReservationSet;

    /**
     * Settle a reserved set to the actual usage per meter (each `<=` its reserved
     * estimate). Unused allowance and leased units return to their pools, and one
     * durable usage event per bucket with non-zero usage is appended.
     *
     * @param  array<string, int>  $actuals  meter => actual units used
     */
    public function commitBuckets(ReservationSet $set, array $actuals): void;

    /** Release a reserved set without charging (error paths). */
    public function releaseBuckets(ReservationSet $set): void;

    /**
     * The outcome-returning face of {@see reserve()} (ADR-0004). Instead of throwing
     * or returning a bare reservation it returns a three-way {@see EnforcementOutcome}
     * so callers and telemetry see WHICH path fired:
     *
     *  - `Allowed`       — carries the held {@see Reservation}.
     *  - `Denied`        — a SEMANTIC refusal (exhausted allowance/quota); fail-closed.
     *  - `Indeterminate` — a dependency was unavailable, resolved by the deployment's
     *                      infra failure policy (fail-open by default) and signalled.
     *
     * A non-positive estimate is a caller bug, not a decision, and still throws.
     *
     * @throws \InvalidArgumentException when the estimate is not positive
     */
    public function reserveOutcome(string $org, string $meter, int $estimate): EnforcementOutcome;

    /**
     * The outcome-returning face of {@see reserveBuckets()} (ADR-0004). Preserves the
     * all-or-nothing multi-bucket semantics: a bucket refused on SEMANTICS makes the
     * whole set `Denied`, while a bucket that cannot be evaluated because a dependency
     * is down makes it `Indeterminate` (resolved by the infra policy and signalled).
     *
     * @param  list<BucketRequest>  $requests
     *
     * @throws \InvalidArgumentException when the request set is empty or an estimate is not positive
     * @throws \LogicException when no {@see MeterPolicyResolver} is configured
     */
    public function reserveBucketsOutcome(string $org, array $requests): EnforcementOutcome;
}
