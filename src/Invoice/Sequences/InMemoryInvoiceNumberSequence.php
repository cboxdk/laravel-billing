<?php

declare(strict_types=1);

namespace Cbox\Billing\Invoice\Sequences;

use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;

/**
 * A per-entity in-memory counter — for tests and single-process use. Production
 * uses a durable, transactional sequence implementing the same contract so
 * numbering survives restarts and stays gapless under concurrency.
 */
class InMemoryInvoiceNumberSequence implements InvoiceNumberSequence
{
    /** @var array<string, int> */
    private array $counters = [];

    public function next(SellerEntity $entity): string
    {
        $number = ($this->counters[$entity->id] ?? 0) + 1;
        $this->counters[$entity->id] = $number;

        return sprintf('%s-%06d', $entity->invoicePrefix, $number);
    }
}
