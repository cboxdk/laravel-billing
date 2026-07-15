<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Enums;

/**
 * How a sale was reversed — the audit distinction that must never be lost:
 *  - Voluntary — a refund we chose to issue; money is returned through the payment
 *                gateway, and a credit note is issued.
 *  - Forced    — a chargeback: an externally-initiated dispute where the money is
 *                pulled by the card network out of band. We never issue the money
 *                movement; we record the dispute, post the reversal, and gate access.
 *
 * The kind is stamped on the ledger posting's `source` (`refund` vs `chargeback`) so
 * the two are distinguishable in the money source of truth, not only in the document.
 */
enum ReversalKind: string
{
    case Voluntary = 'voluntary';
    case Forced = 'forced';

    /** The ledger posting `source` for this kind — the natural-key namespace it dedupes under. */
    public function ledgerSource(): string
    {
        return $this === self::Voluntary ? 'refund' : 'chargeback';
    }
}
