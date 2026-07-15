<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\Contracts;

use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;

/**
 * Builds a {@see Quote} — the convergence point of catalog price, seller-of-record
 * routing, tax, and credits — from requested lines and a context.
 */
interface QuoteBuilder
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function build(array $lines, QuoteContext $context): Quote;
}
