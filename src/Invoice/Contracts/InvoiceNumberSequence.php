<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\Contracts;

use Cbox\Billing\Seller\ValueObjects\SellerEntity;

/**
 * Issues the next legal invoice number for a selling entity. Numbering is
 * per-entity and must be monotonic and gapless — never shared across entities.
 */
interface InvoiceNumberSequence
{
    public function next(SellerEntity $entity): string;
}
