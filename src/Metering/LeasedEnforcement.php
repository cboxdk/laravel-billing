<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering;

use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Metering\Contracts\Enforcement;
use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Contracts\UsageBuffer;
use Cbox\Billing\Metering\Exceptions\QuotaExceeded;
use Cbox\Billing\Metering\ValueObjects\Reservation;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Closure;
use InvalidArgumentException;

/**
 * The app-local, lease-backed enforcement hot path. It holds a leased slice of
 * each organization's allowance in a node-local {@see LocalStore}; a reservation
 * takes from that slice, refilling from the {@see AllowanceLeaseSource} when it
 * runs dry, and denies (hard limit) only when the central budget is exhausted.
 * Committed usage returns any leftover to the local lease and appends a durable
 * {@see UsageEvent} for later sync to billing. Leasing is pessimistic, so no
 * concurrent overspend is possible without a shared hot store — the only drift is
 * leased-but-unused units and reporting lag.
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
     */
    public function __construct(
        private readonly LocalStore $store,
        private readonly AllowanceLeaseSource $source,
        private readonly UsageBuffer $buffer,
        private readonly string $service,
        private readonly int $refillSize = 100,
        ?Closure $ids = null,
        ?Closure $clock = null,
    ) {
        $this->ids = $ids ?? static fn (): string => bin2hex(random_bytes(16));
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    public function reserve(string $org, string $meter, int $estimate): Reservation
    {
        if ($estimate <= 0) {
            throw new InvalidArgumentException('Reservation estimate must be a positive number of units.');
        }

        if (! $this->store->tryTake($org, $meter, $estimate)) {
            // Local lease is short — pull a refill. Lease at least the reservation
            // size so a single large reserve can still be satisfied in one hop.
            $lease = $this->source->lease($org, $meter, max($this->refillSize, $estimate));
            $this->store->addLease($org, $meter, $lease->granted);

            if (! $this->store->tryTake($org, $meter, $estimate)) {
                throw new QuotaExceeded($org, $meter, $estimate);
            }
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
            $this->buffer->append(new UsageEvent(
                id: ($this->ids)(),
                org: $reservation->org,
                meter: $reservation->meter,
                service: $this->service,
                value: $actual,
                occurredAt: ($this->clock)(),
            ));
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
}
