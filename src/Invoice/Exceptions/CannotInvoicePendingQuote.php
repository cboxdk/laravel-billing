<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\Exceptions;

use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use RuntimeException;

/**
 * Raised when trying to invoice a quote whose tax is still pending. An invoice
 * must show a final amount, so the jurisdiction has to be resolved first.
 */
class CannotInvoicePendingQuote extends RuntimeException
{
    public static function forEntity(SellerEntity $seller): self
    {
        return new self(sprintf(
            'Cannot issue an invoice for %s from a tax-pending quote; resolve the jurisdiction first.',
            $seller->legalName,
        ));
    }
}
