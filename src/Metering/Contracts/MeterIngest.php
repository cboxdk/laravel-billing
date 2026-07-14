<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\ValueObjects\UsageEvent;

/**
 * Billing's ingest side: accept usage events into the immutable event log (the
 * metering source of truth). Ingestion is idempotent — an event is counted once
 * per stable dedup key no matter how many times it is (re)delivered — so the
 * SDK/network can retry freely and billing can reprocess without double-counting.
 */
interface MeterIngest
{
    /**
     * Ingest a batch of usage events. Returns the number newly accepted
     * (duplicates within the dedup window are silently ignored).
     *
     * @param  list<UsageEvent>  $events
     */
    public function ingest(array $events): int;
}
