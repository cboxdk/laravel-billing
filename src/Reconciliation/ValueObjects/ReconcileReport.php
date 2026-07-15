<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\ValueObjects;

/**
 * The result of a reconcile batch: the entities that reconciled and the ones that
 * were reported and skipped. A concurrency error never produces a report — it
 * rethrows and aborts the batch (ADR-0003).
 */
readonly class ReconcileReport
{
    /**
     * @param  list<EntityReconciliation>  $reconciled
     * @param  list<ReconcileFailure>  $skipped
     */
    public function __construct(
        public array $reconciled = [],
        public array $skipped = [],
    ) {}
}
