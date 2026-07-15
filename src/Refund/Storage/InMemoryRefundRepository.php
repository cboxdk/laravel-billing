<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Storage;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Refund\Contracts\RefundRepository;
use Cbox\Billing\Refund\ValueObjects\Refund;

/**
 * In-memory {@see RefundRepository} — the zero-config default; also the dogfood store
 * the package's own tests exercise the real refunder against. Single process, not
 * durable; production binds a store on the same connection as the ledger so the refund
 * record and the reversing posting commit together.
 */
class InMemoryRefundRepository implements RefundRepository
{
    /** @var array<string, Refund> keyed by refund id */
    private array $byId = [];

    /** @var array<string, list<Refund>> keyed by invoice number */
    private array $byInvoice = [];

    public function forId(string $refundId): ?Refund
    {
        return $this->byId[$refundId] ?? null;
    }

    public function refundedGross(string $invoiceNumber, string $currency): Money
    {
        $sum = Money::zero($currency);

        foreach ($this->byInvoice[$invoiceNumber] ?? [] as $refund) {
            $sum = $sum->plus($refund->gross);
        }

        return $sum;
    }

    public function save(Refund $refund): void
    {
        $this->byId[$refund->id] = $refund;
        $this->byInvoice[$refund->invoiceNumber()][] = $refund;
    }
}
