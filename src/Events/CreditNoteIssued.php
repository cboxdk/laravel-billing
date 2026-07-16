<?php

declare(strict_types=1);

namespace Cbox\Billing\Events;

use Cbox\Billing\Invoice\ValueObjects\CreditNote;
use Cbox\Billing\Refund\Contracts\Refunder;

/**
 * A credit note was issued: the refund flow ({@see Refunder::refund()}) drew a credit-note
 * number off the seller's own sequence and posted the reversing transaction. Fires once
 * per issued credit note, after the refund is recorded — an idempotent replay of an
 * already-refunded request returns the existing refund without re-firing.
 *
 * Carries the full {@see CreditNote} aggregate (its own number, the referenced invoice
 * number, the account, and the negatively-mirrored net/tax/gross).
 */
readonly class CreditNoteIssued
{
    public function __construct(
        public CreditNote $creditNote,
    ) {}
}
