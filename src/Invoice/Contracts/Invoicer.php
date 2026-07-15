<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\Contracts;

use Cbox\Billing\Account\Exceptions\BillingCurrencyMismatch;
use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use DateTimeImmutable;

/**
 * Turns a confirmed quote into an issued invoice from a selling entity, for a given
 * billing account.
 *
 * Finalizing is where the account's billing currency is fixed: the first finalized
 * invoice stamps and locks the account's currency (one-way), and a later finalization
 * in a different currency is refused. The `$account` identifies the billing account
 * the lock is keyed on — the same org identifier used elsewhere in the package.
 */
interface Invoicer
{
    /**
     * @throws BillingCurrencyMismatch when `$account` is already locked to a currency
     *                                 other than the quote's.
     */
    public function issue(Quote $quote, SellerEntity $seller, string $account, DateTimeImmutable $at): Invoice;
}
