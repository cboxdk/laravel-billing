<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Exceptions\InvalidQuoteLine;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Enums\TaxClass;

/**
 * A requested quote line: what is being bought, how many, and the unit amount (net
 * or gross per the quote's pricing mode). The catalog resolves prices into these.
 *
 * The quantity is validated positive at construction: a zero or negative quantity
 * would multiply a flat/per-unit price into a zero or negative charge, so it is
 * refused here (deny-by-default) rather than producing a wrong invoice downstream.
 *
 * The line's tax class is either a {@see TaxClass} (laravel-tax's taxonomy, the precise
 * choice) or the legacy {@see TaxCategory}. See {@see TaxClass()} for how the legacy
 * `Standard` is read.
 */
readonly class LineInput
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitAmount,
        public TaxCategory|TaxClass $category = TaxCategory::Standard,
    ) {
        if ($quantity <= 0) {
            throw InvalidQuoteLine::nonPositiveQuantity($quantity);
        }
    }

    /**
     * The class the line is taxed as.
     *
     * A billing engine's default line is a subscription to a hosted service, so the legacy
     * `Standard` category reads as an electronically supplied service — the supply it has
     * always been priced as here: reverse-charged business to business across the EU, and
     * taxed where the consumer is otherwise. laravel-tax's own migration maps `Standard` to
     * general GOODS, which would turn an intra-EU B2B subscription into an intra-Community
     * supply of goods and move consumer VAT to the seller's country. Every other legacy
     * category keeps laravel-tax's mapping.
     */
    public function taxClass(): TaxClass
    {
        return match (true) {
            $this->category instanceof TaxClass => $this->category,
            $this->category === TaxCategory::Standard => TaxClass::DigitalService,
            default => $this->category->toClass(),
        };
    }
}
