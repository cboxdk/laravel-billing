<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\Exceptions\QuotaExceeded;
use Cbox\Billing\Metering\ValueObjects\Reservation;

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
}
