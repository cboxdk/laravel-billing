<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Support;

use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\ValueObjects\LedgerLine;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Ledger\ValueObjects\PostingKey;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;

/**
 * Builds the balanced ledger transaction that reverses a sale — shared by the refunder
 * and the chargeback handler so both post the same double-entry shape and differ only
 * in their `source`.
 *
 * The original sale debits the receivable and credits revenue + tax. The reversal is
 * the mirror: it DEBITS revenue (net) and tax, and CREDITS the receivable (gross) —
 * so the tax the sale collected is reversed distinctly, not folded into one line. Line
 * amounts are the positive magnitudes being reversed; direction carries the sign, per
 * the ledger's non-negative-amount invariant.
 *
 * Idempotency rides the natural {@see PostingKey} `(account, source, reference)`: the
 * `source` is `refund` for a voluntary reversal and `chargeback` for a forced one
 * (see {@see ReversalKind::ledgerSource()}), so a re-post is a no-op AND the two kinds
 * are distinguishable in the money source of truth.
 */
readonly class ReversalPosting
{
    public static function build(
        string $account,
        SellerEntity $seller,
        Money $net,
        Money $tax,
        Money $gross,
        ReversalKind $kind,
        string $reference,
        string $transactionId,
        string $memo,
        int $occurredAt = 0,
    ): LedgerTransaction {
        $receivable = 'receivable:'.$account;
        $revenue = 'revenue:'.$seller->id;
        $taxAccount = 'tax:'.$seller->id;

        $lines = [
            new LedgerLine($revenue, Direction::Debit, $net),
        ];

        if ($tax->isPositive()) {
            $lines[] = new LedgerLine($taxAccount, Direction::Debit, $tax);
        }

        $lines[] = new LedgerLine($receivable, Direction::Credit, $gross);

        return new LedgerTransaction(
            id: $transactionId,
            lines: $lines,
            memo: $memo,
            occurredAt: $occurredAt,
            key: new PostingKey($account, $kind->ledgerSource(), $reference),
        );
    }
}
