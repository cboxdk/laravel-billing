<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\Contracts;

use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use DateTimeImmutable;

/**
 * Turns a confirmed quote into an issued invoice from a selling entity.
 */
interface Invoicer
{
    public function issue(Quote $quote, SellerEntity $seller, DateTimeImmutable $at): Invoice;
}
