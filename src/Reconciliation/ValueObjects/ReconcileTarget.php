<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\ValueObjects;

/**
 * The unit of reconciliation: a single `(org, meter)` entity. The reconciler
 * processes a batch of these independently — one target's failure never fails the
 * batch (ADR-0003), and each carries its own checkpoint.
 */
readonly class ReconcileTarget
{
    public function __construct(
        public string $org,
        public string $meter,
    ) {}
}
