<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\MeterIngest;

/**
 * The default ingest: append incoming usage events to the {@see EventLog}. Because
 * the log dedups by event id, ingestion is idempotent — a retried or reprocessed
 * batch never double-counts.
 */
readonly class DefaultMeterIngest implements MeterIngest
{
    public function __construct(private EventLog $log) {}

    public function ingest(array $events): int
    {
        return $this->log->append($events);
    }
}
