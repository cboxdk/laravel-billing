<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering;

use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Metering\Contracts\Enforcement;
use Cbox\Billing\Metering\Contracts\EnforcementSignals;
use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Contracts\UsageBuffer;
use Cbox\Billing\Metering\Enums\DenialReason;
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\Exceptions\MeterNotEntitled;
use Cbox\Billing\Metering\Exceptions\QuotaExceeded;
use Cbox\Billing\Metering\Signals\NullEnforcementSignals;
use Cbox\Billing\Metering\ValueObjects\BucketRequest;
use Cbox\Billing\Metering\ValueObjects\BucketReservation;
use Cbox\Billing\Metering\ValueObjects\EnforcementOutcome;
use Cbox\Billing\Metering\ValueObjects\InfraFault;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Metering\ValueObjects\Reservation;
use Cbox\Billing\Metering\ValueObjects\ReservationSet;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * The app-local, lease-backed enforcement hot path. It holds a leased slice of
 * each organization's allowance in a node-local {@see LocalStore}; a reservation
 * takes from that slice, refilling from the {@see AllowanceLeaseSource} when it
 * runs dry, and denies (hard limit) only when the central budget is exhausted.
 * Committed usage returns any leftover to the local lease and appends a durable
 * {@see UsageEvent} for later sync to billing. Leasing is pessimistic, so no
 * concurrent overspend is possible without a shared hot store — the only drift is
 * leased-but-unused units and reporting lag.
 *
 * Beyond the single-meter path it enforces multi-dimensional requests (ADR-0005):
 * {@see reserveBuckets()} reserves a SET of independent `(org, meter)` buckets, each
 * evaluated against its own {@see MeterPolicy}
 * — entitlement first, then an isolated allowance claimed as an atomic disjoint
 * slice, then weighted overage cost, then overage behaviour — never collapsing the
 * buckets into a single number. The single-meter methods are the set-of-one case.
 */
class LeasedEnforcement implements Enforcement
{
    /** @var Closure(): string */
    private Closure $ids;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param  int  $refillSize  units requested per refill (a large reserve leases at least its own size)
     * @param  (Closure(): string)|null  $ids  id/dedup-key factory (deterministic in tests)
     * @param  (Closure(): int)|null  $clock  millisecond epoch clock (deterministic in tests)
     * @param  MeterPolicyResolver|null  $policies  per-bucket policy source; required only for the multi-bucket path
     * @param  EnforcementSignals  $signals  where fail-open/fail-closed infra signals are emitted (ADR-0004); defaults to a no-op
     * @param  InfraFailurePolicy  $infraPolicy  how an indeterminate (dependency-down) decision resolves; fail-open by default
     */
    public function __construct(
        private readonly LocalStore $store,
        private readonly AllowanceLeaseSource $source,
        private readonly UsageBuffer $buffer,
        private readonly string $service,
        private readonly int $refillSize = 100,
        ?Closure $ids = null,
        ?Closure $clock = null,
        private readonly ?MeterPolicyResolver $policies = null,
        private readonly EnforcementSignals $signals = new NullEnforcementSignals,
        private readonly InfraFailurePolicy $infraPolicy = InfraFailurePolicy::Allow,
    ) {
        $this->ids = $ids ?? static fn (): string => bin2hex(random_bytes(16));
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    public function reserve(string $org, string $meter, int $estimate): Reservation
    {
        if ($estimate <= 0) {
            throw new InvalidArgumentException('Reservation estimate must be a positive number of units.');
        }

        if (! $this->holdLease($org, $meter, $estimate)) {
            throw new QuotaExceeded($org, $meter, $estimate);
        }

        return new Reservation(($this->ids)(), $org, $meter, $estimate);
    }

    public function commit(Reservation $reservation, int $actual): void
    {
        if ($actual < 0 || $actual > $reservation->amount) {
            throw new InvalidArgumentException('Committed amount must be between 0 and the reserved estimate.');
        }

        $leftover = $reservation->amount - $actual;
        if ($leftover > 0) {
            $this->store->giveBack($reservation->org, $reservation->meter, $leftover);
        }

        if ($actual > 0) {
            $this->recordUsage($reservation->org, $reservation->meter, $actual);
        }
    }

    public function release(Reservation $reservation): void
    {
        $this->store->giveBack($reservation->org, $reservation->meter, $reservation->amount);
    }

    public function balance(string $org, string $meter): int
    {
        return $this->store->remaining($org, $meter);
    }

    public function reserveBuckets(string $org, array $requests): ReservationSet
    {
        if ($this->policies === null) {
            throw new LogicException('A MeterPolicyResolver is required for multi-bucket enforcement.');
        }

        if ($requests === []) {
            throw new InvalidArgumentException('A bucket reservation must carry at least one meter.');
        }

        /** @var list<BucketReservation> $buckets */
        $buckets = [];

        try {
            foreach ($requests as $request) {
                $buckets[] = $this->reserveBucket($org, $request);
            }
        } catch (Throwable $e) {
            // All-or-nothing: unwind every bucket already held. The failing bucket
            // self-cleans before it throws, so only the completed ones remain. The
            // unwind is best-effort — when the failure IS the store being down, its
            // release calls will throw too; swallow that so the original cause (which
            // the outcome path classifies as infra vs semantic) is what propagates.
            try {
                $this->rollBack($org, $buckets);
            } catch (Throwable) {
                // Store unavailable mid-unwind; leased/claimed units are reconciled.
            }

            throw $e;
        }

        return new ReservationSet(($this->ids)(), $org, $buckets);
    }

    public function commitBuckets(ReservationSet $set, array $actuals): void
    {
        foreach ($set->buckets as $bucket) {
            if (! array_key_exists($bucket->meter, $actuals)) {
                throw new InvalidArgumentException("Missing committed usage for meter [{$bucket->meter}].");
            }

            $actual = $actuals[$bucket->meter];
            if ($actual < 0 || $actual > $bucket->estimate) {
                throw new InvalidArgumentException("Committed amount for meter [{$bucket->meter}] must be between 0 and the reserved estimate.");
            }

            $leftover = $bucket->estimate - $actual;

            // The unused tail of the slice is billable-first (the high positions),
            // so return unused leased units before touching the exempt low positions.
            $unusedBillable = min($leftover, $bucket->billable);
            if ($unusedBillable > 0) {
                $this->store->giveBack($set->org, $bucket->meter, $unusedBillable);
            }

            // Return the unused allowance consumption so the next claim starts from
            // the actually-consumed position, not the over-reserved estimate.
            if ($leftover > 0) {
                $this->store->releaseAllowance($set->org, $bucket->meter, $leftover);
            }

            if ($actual > 0) {
                $this->recordUsage($set->org, $bucket->meter, $actual);
            }
        }
    }

    public function releaseBuckets(ReservationSet $set): void
    {
        foreach ($set->buckets as $bucket) {
            $this->store->releaseAllowance($set->org, $bucket->meter, $bucket->estimate);

            if ($bucket->billable > 0) {
                $this->store->giveBack($set->org, $bucket->meter, $bucket->billable);
            }
        }
    }

    public function reserveOutcome(string $org, string $meter, int $estimate): EnforcementOutcome
    {
        return $this->decide($org, [$meter], fn (): Reservation => $this->reserve($org, $meter, $estimate));
    }

    public function reserveBucketsOutcome(string $org, array $requests): EnforcementOutcome
    {
        $meters = array_map(
            static fn (BucketRequest $request): string => $request->meter,
            $requests,
        );

        return $this->decide($org, $meters, fn (): ReservationSet => $this->reserveBuckets($org, $requests));
    }

    /**
     * Run a throw-based reservation and fold its result into a three-way outcome
     * (ADR-0004). The failure policy is split by CAUSE, not uniformly:
     *
     *  - A reached refusal — {@see MeterNotEntitled} (unknown/disabled meter) or
     *    {@see QuotaExceeded} (exhausted allowance/quota) — is SEMANTIC and fails
     *    closed to `Denied`, carrying the precise reason.
     *  - A caller bug ({@see InvalidArgumentException}/{@see LogicException}: bad
     *    estimate, empty set, missing resolver) is neither a decision nor an infra
     *    fault — it is re-thrown, never swallowed into an outcome.
     *  - Any other throwable is an INFRASTRUCTURE fault (store/cache down, lock/lease
     *    timeout, transport error): the decision is `Indeterminate`, resolved by the
     *    configured infra policy (fail-open by default) and signalled so operators see
     *    the infra path fired.
     *
     * @param  list<string>  $meters
     * @param  Closure(): (Reservation|ReservationSet)  $attempt
     */
    private function decide(string $org, array $meters, Closure $attempt): EnforcementOutcome
    {
        try {
            return EnforcementOutcome::allowed($org, $meters, $attempt());
        } catch (MeterNotEntitled $e) {
            return EnforcementOutcome::denied($org, $meters, $e->denialReason);
        } catch (QuotaExceeded) {
            return EnforcementOutcome::denied($org, $meters, DenialReason::QuotaExhausted);
        } catch (InvalidArgumentException|LogicException $e) {
            // A programming error, not a metering decision or an outage — surface it.
            throw $e;
        } catch (Throwable $e) {
            $outcome = EnforcementOutcome::indeterminate($org, $meters, InfraFault::from($e), $this->infraPolicy);
            $this->signals->indeterminate($outcome);

            return $outcome;
        }
    }

    /**
     * Reserve one bucket. Order: entitlement FIRST, then isolated allowance, then
     * weighted overage cost + behaviour. If it refuses after claiming its slice it
     * rolls that slice back itself, so the set-level unwind only handles the buckets
     * that were fully held.
     */
    private function reserveBucket(string $org, BucketRequest $request): BucketReservation
    {
        $meter = $request->meter;
        $estimate = $request->estimate;

        if ($estimate <= 0) {
            throw new InvalidArgumentException('Reservation estimate must be a positive number of units.');
        }

        // 1. Entitlement is checked FIRST — before any allowance/cost math — so a
        //    disabled or unknown meter is refused rather than computing zero overage
        //    and running for free. Unknown → deny-by-default.
        $policy = $this->policies?->resolve($org, $meter);
        if ($policy === null) {
            throw MeterNotEntitled::unknown($org, $meter);
        }
        if (! $policy->enabled) {
            throw MeterNotEntitled::disabled($org, $meter);
        }

        // 2. Isolated allowance: atomically claim a disjoint [start, start+estimate)
        //    slice and compute the exemption from the CLAIMED position. This bucket's
        //    allowance is its own pool — it never draws from another meter's.
        $start = $this->store->claimAllowance($org, $meter, $estimate);

        $exempt = $policy->exemptWithin($start, $estimate);
        $billable = $estimate - $exempt;

        // 3 + 4. Weighted overage cost + behaviour. Exempt units are free; only the
        //        overage is charged (and its cost is Σ-summed across buckets).
        if ($billable > 0 && ! $policy->unlimited) {
            if ($policy->overage === OverageBehaviour::Block) {
                // Over the isolated allowance under a hard block — roll back this
                // claim and refuse.
                $this->store->releaseAllowance($org, $meter, $estimate);

                throw new QuotaExceeded($org, $meter, $billable);
            }

            // Bill: draw the overage from the leased paid budget (pessimistic hard
            // spend cap — exhausted budget refuses rather than overspends).
            if (! $this->holdLease($org, $meter, $billable)) {
                $this->store->releaseAllowance($org, $meter, $estimate);

                throw new QuotaExceeded($org, $meter, $billable);
            }
        }

        return new BucketReservation($meter, $estimate, $start, $exempt, $billable, $policy);
    }

    /**
     * Undo every fully-held bucket (allowance consumption + any leased overage) when
     * a later bucket in the set is refused — the reservation is all-or-nothing.
     *
     * @param  list<BucketReservation>  $buckets
     */
    private function rollBack(string $org, array $buckets): void
    {
        foreach ($buckets as $bucket) {
            $this->store->releaseAllowance($org, $bucket->meter, $bucket->estimate);

            if ($bucket->billable > 0) {
                $this->store->giveBack($org, $bucket->meter, $bucket->billable);
            }
        }
    }

    /**
     * Take `$amount` units from the local lease, refilling from the source once if
     * the local slice is short. Returns false when the central budget is exhausted.
     */
    private function holdLease(string $org, string $meter, int $amount): bool
    {
        if ($this->store->tryTake($org, $meter, $amount)) {
            return true;
        }

        // Local lease is short — pull a refill. Lease at least the requested size so
        // a single large hold can still be satisfied in one hop.
        $lease = $this->source->lease($org, $meter, max($this->refillSize, $amount));
        $this->store->addLease($org, $meter, $lease->granted);

        return $this->store->tryTake($org, $meter, $amount);
    }

    private function recordUsage(string $org, string $meter, int $value): void
    {
        $this->buffer->append(new UsageEvent(
            id: ($this->ids)(),
            org: $org,
            meter: $meter,
            service: $this->service,
            value: $value,
            occurredAt: ($this->clock)(),
        ));
    }
}
