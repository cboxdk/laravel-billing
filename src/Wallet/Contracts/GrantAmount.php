<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Contracts;

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\ValueObjects\Distributed;
use Cbox\Billing\Wallet\ValueObjects\Fixed;
use Cbox\Billing\Wallet\ValueObjects\GrantSlice;
use DateTimeImmutable;

/**
 * How a grant's per-cadence amount is sized within a billing period (ADR-0014):
 *
 *  - {@see Fixed} — grant a fixed `amount` at each
 *    cadence boundary (ADR-0013 as-is);
 *  - {@see Distributed} — split a period `total`
 *    evenly across the cadence slices in the period, remainder-safe.
 *
 * `slices()` expands the amount over `[start, end)` into the concrete per-boundary
 * lots the scheduler grants. Applies uniformly to credits AND included allowances
 * (unified pool grants, ADR-0013).
 */
interface GrantAmount
{
    public function cadence(): GrantCadence;

    /**
     * The per-boundary slices within the billing period `[start, end)`. Each carries
     * its integer amount and its cadence period end (for the expiry policy). For a
     * `Distributed` amount the slice amounts sum EXACTLY to the stated total.
     *
     * @return non-empty-list<GrantSlice>
     */
    public function slices(DateTimeImmutable $start, DateTimeImmutable $end): array;
}
