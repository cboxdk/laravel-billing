<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Storage;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Relational {@see EventLog} for MySQL/Postgres (and sqlite in tests) — enough for
 * most deployments without reaching for a columnar store. Appends are idempotent
 * via a unique index on the event id (`insertOrIgnore`), and every billable-metric
 * aggregation is computed IN THE DATABASE. The table is append-only; rows are never
 * updated or deleted.
 */
readonly class DatabaseEventLog implements EventLog
{
    private const TABLE = 'billing_usage_events';

    public function __construct(private ConnectionInterface $db) {}

    public function append(array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $rows = array_map(static fn (UsageEvent $event): array => [
            'event_id' => $event->id,
            'org' => $event->org,
            'meter' => $event->meter,
            'service' => $event->service,
            'value' => $event->value,
            'occurred_at' => $event->occurredAt,
            'unique_key' => $event->uniqueKey,
            'weight' => $event->weight,
        ], $events);

        return $this->db->table(self::TABLE)->insertOrIgnore($rows);
    }

    public function sum(string $org, string $meter, int $fromMs, int $toMs): int
    {
        return $this->aggregate($org, $meter, $fromMs, $toMs, Aggregation::Sum);
    }

    public function aggregate(string $org, string $meter, int $fromMs, int $toMs, Aggregation $aggregation): int
    {
        $query = $this->scoped($org, $meter, $fromMs, $toMs);

        return match ($aggregation) {
            Aggregation::Count => $query->count(),
            Aggregation::Sum => $this->toInt($query->sum('value')),
            Aggregation::Max => $this->toInt($query->max('value')),
            Aggregation::UniqueCount => $query->distinct()->count('unique_key'),
            Aggregation::Latest => $this->toInt($query
                ->orderByDesc('occurred_at')
                ->orderByDesc('event_id')
                ->value('value')),
            Aggregation::WeightedSum => $this->toInt($query->sum($this->db->raw('value * weight'))),
        };
    }

    /** Coerce a database aggregate result (numeric, or null for an empty window) to int. */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function scoped(string $org, string $meter, int $fromMs, int $toMs): Builder
    {
        return $this->db->table(self::TABLE)
            ->where('org', $org)
            ->where('meter', $meter)
            ->whereBetween('occurred_at', [$fromMs, $toMs]);
    }
}
