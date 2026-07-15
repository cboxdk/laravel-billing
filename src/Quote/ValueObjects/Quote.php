<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Quote\Enums\QuoteStatus;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\ValueObjects\SellerRegistrations;

/**
 * The full, confirmable consequence of a purchase/change: priced lines with tax,
 * the totals (incl. credit and amount due now), which selling entity issues it,
 * the place of supply, and whether tax is fully resolved. This is what a confirm
 * step shows; the committed charge equals `totals->dueNow`.
 */
readonly class Quote
{
    /**
     * @param  list<QuoteLine>  $lines
     */
    public function __construct(
        public array $lines,
        public QuoteTotals $totals,
        public string $currency,
        public SellerRegistrations $seller,
        public Jurisdiction $place,
        public TaxResolution $taxResolution,
        public QuoteStatus $status = QuoteStatus::Draft,
    ) {}

    public function isTaxResolved(): bool
    {
        return $this->taxResolution->resolved;
    }
}
