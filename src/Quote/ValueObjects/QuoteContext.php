<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\Enums\Pricing;
use Cbox\Tax\ValueObjects\SellerRegistrations;

/**
 * The buyer/seller context a quote is built against: where the customer belongs,
 * who they are, which selling entity issues it (multi-entity routing), the pricing
 * mode, whether their tax ID is validated (gates reverse charge), and any wallet
 * credit available to apply.
 */
readonly class QuoteContext
{
    public function __construct(
        public Jurisdiction $place,
        public CustomerType $customer,
        public SellerRegistrations $seller,
        public Pricing $pricing = Pricing::Exclusive,
        public bool $customerTaxIdValidated = false,
        public ?Money $creditAvailable = null,
    ) {}
}
