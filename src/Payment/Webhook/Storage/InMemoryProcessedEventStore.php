<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Webhook\Storage;

use Cbox\Billing\Payment\Contracts\ProcessedEventStore;

/**
 * In-memory {@see ProcessedEventStore} — the zero-config default and the base the test
 * fake extends. Single process, not durable; production binds a durable store (a unique
 * row per event id) on the same contract.
 */
class InMemoryProcessedEventStore implements ProcessedEventStore
{
    /** @var array<string, true> seen event ids */
    protected array $seen = [];

    public function remember(string $eventId): bool
    {
        if (isset($this->seen[$eventId])) {
            return false;
        }

        $this->seen[$eventId] = true;

        return true;
    }
}
