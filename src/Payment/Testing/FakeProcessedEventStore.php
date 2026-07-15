<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

use Cbox\Billing\Payment\Webhook\Storage\InMemoryProcessedEventStore;

/**
 * Test fake for the event-id dedup store — the in-memory default with a peek helper so a
 * test can assert an event id was (or was not) recorded without mutating the ledger.
 */
class FakeProcessedEventStore extends InMemoryProcessedEventStore
{
    /** Whether `$eventId` has already been remembered, without recording it. */
    public function hasSeen(string $eventId): bool
    {
        return isset($this->seen[$eventId]);
    }
}
