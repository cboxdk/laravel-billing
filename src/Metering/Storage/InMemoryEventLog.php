<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Storage;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;

/**
 * In-memory {@see EventLog} — for tests and zero-config use. Dedups by event id;
 * aggregates by filtering. Not durable — production uses {@see DatabaseEventLog} or a
 * ClickHouse adapter.
 */
class InMemoryEventLog implements EventLog
{
    /** @var array<string, UsageEvent> keyed by event id */
    private array $events = [];

    public function append(array $events): int
    {
        $appended = 0;

        foreach ($events as $event) {
            if (! isset($this->events[$event->id])) {
                $this->events[$event->id] = $event;
                $appended++;
            }
        }

        return $appended;
    }

    public function sum(string $org, string $meter, int $fromMs, int $toMs): int
    {
        return $this->aggregate($org, $meter, $fromMs, $toMs, Aggregation::Sum);
    }

    public function aggregate(string $org, string $meter, int $fromMs, int $toMs, Aggregation $aggregation): int
    {
        $window = $this->window($org, $meter, $fromMs, $toMs);

        return match ($aggregation) {
            Aggregation::Count => count($window),
            Aggregation::Sum => array_sum(array_map(static fn (UsageEvent $e): int => $e->value, $window)),
            Aggregation::Max => $this->max($window),
            Aggregation::UniqueCount => $this->uniqueCount($window),
            Aggregation::Latest => $this->latest($window),
            Aggregation::WeightedSum => array_sum(array_map(static fn (UsageEvent $e): int => $e->value * $e->weight, $window)),
        };
    }

    /**
     * The events for `$org`/`$meter` within the inclusive window, in append order.
     *
     * @return list<UsageEvent>
     */
    private function window(string $org, string $meter, int $fromMs, int $toMs): array
    {
        $matched = [];

        foreach ($this->events as $event) {
            if ($event->org === $org
                && $event->meter === $meter
                && $event->occurredAt >= $fromMs
                && $event->occurredAt <= $toMs
            ) {
                $matched[] = $event;
            }
        }

        return $matched;
    }

    /** @param  list<UsageEvent>  $window */
    private function max(array $window): int
    {
        $max = 0;
        $seen = false;

        foreach ($window as $event) {
            if (! $seen || $event->value > $max) {
                $max = $event->value;
                $seen = true;
            }
        }

        return $max;
    }

    /** @param  list<UsageEvent>  $window */
    private function uniqueCount(array $window): int
    {
        $keys = [];

        foreach ($window as $event) {
            if ($event->uniqueKey !== null) {
                $keys[$event->uniqueKey] = true;
            }
        }

        return count($keys);
    }

    /**
     * The value of the most recent event — greatest `occurredAt`, tie-broken by the
     * lexicographically-greatest event id so it matches the database ordering exactly.
     *
     * @param  list<UsageEvent>  $window
     */
    private function latest(array $window): int
    {
        $latest = null;

        foreach ($window as $event) {
            if ($latest === null
                || $event->occurredAt > $latest->occurredAt
                || ($event->occurredAt === $latest->occurredAt && $event->id > $latest->id)
            ) {
                $latest = $event;
            }
        }

        return $latest === null ? 0 : $latest->value;
    }
}
