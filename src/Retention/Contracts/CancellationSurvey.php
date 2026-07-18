<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Contracts;

use Cbox\Billing\Retention\NullCancellationSurvey;
use Cbox\Billing\Retention\ValueObjects\CancellationReason;

/**
 * The merchant-configured reasons a subscriber is offered when they cancel a subscription.
 * This is the seam only: the engine ships the {@see NullCancellationSurvey}
 * default (an empty list → a plain cancel with no survey), the deployable app binds a basic
 * default, and the private retention plugin binds the rich, per-merchant survey.
 *
 * Bound contracts-first via `bindIf`, so the first binder wins and the engine never forces a
 * survey onto a host that does not want one.
 */
interface CancellationSurvey
{
    /**
     * The reasons to offer for cancelling `$subscriptionId` on `$account`. An empty list
     * means "no survey" — the host proceeds straight to a plain cancel.
     *
     * @return list<CancellationReason>
     */
    public function reasonsFor(string $account, string $subscriptionId): array;
}
