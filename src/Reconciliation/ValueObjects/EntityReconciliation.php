<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\ValueObjects;

/**
 * The outcome of reconciling one entity in a cycle: the signed deltas posted to the
 * ledger and the cumulative totals the checkpoint was advanced to. A `meterDelta`
 * may be negative when usage aged out of the live window into the aged-out bucket (a
 * re-attribution); `agedDelta` is always non-negative.
 */
readonly class EntityReconciliation
{
    public function __construct(
        public ReconcileTarget $target,
        public int $meterDelta,
        public int $agedDelta,
        public int $meterTotal,
        public int $agedTotal,
        public int $reconciledThroughMs,
        public int $agedThroughMs,
    ) {}

    /** Whether this cycle posted anything to the ledger. */
    public function posted(): bool
    {
        return $this->meterDelta !== 0 || $this->agedDelta !== 0;
    }
}
