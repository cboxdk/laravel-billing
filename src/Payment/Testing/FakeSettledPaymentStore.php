<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

use Cbox\Billing\Payment\Webhook\Storage\InMemorySettledPaymentStore;

/**
 * Test fake for the settle-once store — the in-memory default with a helper to count how
 * many references have been settled, so a test can assert a reference settled exactly once.
 */
class FakeSettledPaymentStore extends InMemorySettledPaymentStore
{
    /** How many distinct references have been settled. */
    public function settledCount(): int
    {
        return count($this->settled);
    }
}
