<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention;

use Cbox\Billing\Retention\Contracts\RetentionOffers;

/**
 * The default {@see RetentionOffers}: presents no offers. With this bound (the engine's
 * `bindIf` default), a cancel surfaces no save-offers and proceeds straight through. The
 * deployable app binds a basic default over this, and the private retention plugin binds the
 * rich offer logic; the engine stays inert until one of them does.
 */
readonly class NullRetentionOffers implements RetentionOffers
{
    public function offersFor(string $account, string $subscriptionId): array
    {
        return [];
    }
}
