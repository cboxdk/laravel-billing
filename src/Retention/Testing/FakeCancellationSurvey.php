<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Testing;

use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\ValueObjects\CancellationReason;

/**
 * A configurable {@see CancellationSurvey} for tests — stands in for the app's basic default
 * or the plugin's rich survey. Give it the reasons to offer with {@see offer()}; it returns
 * them for every `(account, subscription)`, so a test can assert the seam surfaces exactly the
 * configured reasons (and the Null default returns none without one).
 */
class FakeCancellationSurvey implements CancellationSurvey
{
    /** @var list<CancellationReason> */
    private array $reasons = [];

    public function offer(CancellationReason ...$reasons): self
    {
        $this->reasons = array_values($reasons);

        return $this;
    }

    public function reasonsFor(string $account, string $subscriptionId): array
    {
        return $this->reasons;
    }
}
