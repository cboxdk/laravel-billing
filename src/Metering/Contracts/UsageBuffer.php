<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\ValueObjects\UsageEvent;

/**
 * A durable local buffer the SDK appends every committed usage event to BEFORE
 * syncing to billing — the crash-safety story. If the in-memory counter dies the
 * buffer replays; if the whole node dies only usage since the last drained sync
 * is lost. Production backs this with a durable local queue/WAL; tests use an
 * array.
 */
interface UsageBuffer
{
    public function append(UsageEvent $event): void;

    /**
     * Take up to `limit` buffered events for shipping to billing's ingest,
     * removing them from the buffer. Returns them oldest-first.
     *
     * @return list<UsageEvent>
     */
    public function drain(int $limit = 1000): array;

    public function size(): int;
}
