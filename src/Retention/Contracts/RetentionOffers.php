<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Contracts;

use Cbox\Billing\Retention\NullRetentionOffers;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;

/**
 * The save-offers to present to a subscriber who is cancelling. This is the seam only: the
 * engine ships the {@see NullRetentionOffers} default (an empty list
 * → present nothing, cancel proceeds), the deployable app binds a basic default, and the
 * private retention plugin binds the rich offer logic (targeting, eligibility, caps, …).
 *
 * Bound contracts-first via `bindIf`, so the first binder wins and the engine never forces
 * an offer onto a host that does not want one.
 */
interface RetentionOffers
{
    /**
     * The save-offers to present for `$subscriptionId` on `$account`. An empty list means
     * "no offers" — the host proceeds straight to the cancel.
     *
     * @return list<SaveOffer>
     */
    public function offersFor(string $account, string $subscriptionId): array;
}
