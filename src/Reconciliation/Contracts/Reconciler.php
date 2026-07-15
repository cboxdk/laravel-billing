<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\Contracts;

use Cbox\Billing\Reconciliation\ValueObjects\ReconcileReport;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;

/**
 * Closes the gap between the fast hot-path counter and the durable ledger by
 * **converging on a cumulative delta**, not by replaying events (ADR-0003). For each
 * entity it reads the cumulative usage total from the durable event log, subtracts
 * the prior checkpoint total, and posts the difference to the ledger.
 *
 * Late, duplicated, or out-of-order events need no special handling: the next cycle's
 * arithmetic re-derives the cumulative total and posts only what is newly owed, so
 * the ledger self-corrects.
 */
interface Reconciler
{
    /**
     * Reconcile a batch of entities. One entity's failure is reported and skipped so
     * it cannot fail the batch; a concurrency/deadlock error rethrows and aborts the
     * batch (ADR-0003). `$now` (ms epoch) defaults to the injected clock.
     *
     * @param  iterable<ReconcileTarget>  $targets
     */
    public function reconcile(iterable $targets, ?int $now = null): ReconcileReport;
}
