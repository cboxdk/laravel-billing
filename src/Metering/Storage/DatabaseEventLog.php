<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Storage;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\ValueObjects\UsageEvent;
use Illuminate\Database\ConnectionInterface;

/**
 * Relational {@see EventLog} for MySQL/Postgres (and sqlite in tests) — enough for
 * most deployments without reaching for a columnar store. Appends are idempotent
 * via a unique index on the event id (`insertOrIgnore`), and sums aggregate in the
 * database. The table is append-only; rows are never updated or deleted.
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
        ], $events);

        return $this->db->table(self::TABLE)->insertOrIgnore($rows);
    }

    public function sum(string $org, string $meter, int $fromMs, int $toMs): int
    {
        $sum = $this->db->table(self::TABLE)
            ->where('org', $org)
            ->where('meter', $meter)
            ->whereBetween('occurred_at', [$fromMs, $toMs])
            ->sum('value');

        return (int) $sum;
    }
}
