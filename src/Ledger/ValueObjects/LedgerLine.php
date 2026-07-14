<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\ValueObjects;

use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Money\Money;

/**
 * One posting: a debit or credit of `amount` against `account`. Accounts are
 * opaque keys (e.g. "wallet:org_a:prepaid", "revenue:api", "receivable:org_a").
 */
readonly class LedgerLine
{
    public function __construct(
        public string $account,
        public Direction $direction,
        public Money $amount,
    ) {}
}
