<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Enums;

/**
 * Where an issued invoice sits in its payment lifecycle, from dunning's point of view:
 *
 *  - Open          — issued and not yet settled; it is what dunning chases.
 *  - Paid          — settled in full; no longer a debt.
 *  - Uncollectible — written off (bad debt). The money is not expected to arrive, but
 *                    the debt is NOT gone: an uncollectible invoice blocks an account
 *                    from being restored to access, so clearing *some* debt never
 *                    silently reopens the door while written-off debt remains.
 *
 * Deliberately narrow — dunning only distinguishes "still owed", "settled", and
 * "written off". Anything richer belongs to the invoice/ledger source of truth.
 */
enum InvoicePaymentState: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Uncollectible = 'uncollectible';

    /** Whether the invoice is still an outstanding debt dunning should chase. */
    public function isOutstanding(): bool
    {
        return $this === self::Open;
    }

    /** Whether the invoice, by being written off, holds an account below the access line. */
    public function blocksRestore(): bool
    {
        return $this === self::Uncollectible;
    }
}
