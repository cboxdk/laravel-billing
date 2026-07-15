<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\Contracts;

use Cbox\Billing\Seller\ValueObjects\SellerEntity;

/**
 * Issues the next legal credit-note number for a selling entity. Like invoice
 * numbering it is per-entity, monotonic and gapless, but it is a SEPARATE sequence:
 * a credit note is its own legal document and never draws (or reuses) an invoice
 * number.
 */
interface CreditNoteNumberSequence
{
    public function next(SellerEntity $entity): string;
}
