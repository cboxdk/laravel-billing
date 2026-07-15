<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\ValueObjects;

use Throwable;

/**
 * A per-entity reconciliation that was reported and skipped so it could not fail the
 * batch (ADR-0003). Concurrency/deadlock errors are NOT captured here — those rethrow
 * out of the reconciler, because a swallowed deadlock would leave the outer
 * transaction half-rolled-back.
 */
readonly class ReconcileFailure
{
    public function __construct(
        public ReconcileTarget $target,
        public Throwable $error,
    ) {}
}
