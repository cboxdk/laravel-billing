<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Buffers;

use Cbox\Billing\Metering\Contracts\UsageBuffer;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;

/**
 * In-memory {@see UsageBuffer} for a single process and for tests. A production
 * SDK backs this with a durable local queue/WAL so a crash after append but
 * before sync still replays; the interface is identical.
 */
class ArrayUsageBuffer implements UsageBuffer
{
    /** @var list<UsageEvent> */
    private array $events = [];

    public function append(UsageEvent $event): void
    {
        $this->events[] = $event;
    }

    public function drain(int $limit = 1000): array
    {
        $taken = array_slice($this->events, 0, max(0, $limit));
        $this->events = array_slice($this->events, count($taken));

        return $taken;
    }

    public function size(): int
    {
        return count($this->events);
    }
}
