<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention;

use Cbox\Billing\Retention\Contracts\CancellationSurvey;

/**
 * The default {@see CancellationSurvey}: offers no reasons. With this bound (the engine's
 * `bindIf` default), a cancel presents no survey — a plain cancel. The deployable app binds
 * a basic default over this, and the private retention plugin binds the rich survey; the
 * engine stays inert until one of them does.
 */
readonly class NullCancellationSurvey implements CancellationSurvey
{
    public function reasonsFor(string $account, string $subscriptionId): array
    {
        return [];
    }
}
