<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\ValueObjects;

use Cbox\Billing\Quote\ValueObjects\QuoteLine;
use Cbox\Billing\Quote\ValueObjects\QuoteTotals;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Geo\ValueObjects\Jurisdiction;
use DateTimeImmutable;

/**
 * An issued invoice: a confirmed quote, fixed to a legal number from the selling
 * entity's own sequence, the entity that issued it, and the issue date. It reuses
 * the quote's priced lines and totals — the charged amount equals what was shown.
 */
readonly class Invoice
{
    /**
     * @param  list<QuoteLine>  $lines
     */
    public function __construct(
        public string $number,
        public SellerEntity $seller,
        public Jurisdiction $place,
        public string $currency,
        public array $lines,
        public QuoteTotals $totals,
        public DateTimeImmutable $issuedAt,
    ) {}
}
