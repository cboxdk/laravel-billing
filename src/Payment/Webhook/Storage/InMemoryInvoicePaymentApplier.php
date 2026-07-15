<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Webhook\Storage;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * In-memory {@see InvoicePaymentApplier} — the zero-config default and the base the test
 * fake extends. The engine does not own invoices, so the default simply RECORDS each
 * settlement it is asked to apply (single process, not durable); a host binds its own
 * applier that writes the invoice's paid state. Recording lets the package dogfood the
 * exactly-once ingest and assert an invoice was settled exactly once.
 */
class InMemoryInvoicePaymentApplier implements InvoicePaymentApplier
{
    /** @var array<string, int> times each reference was marked paid */
    protected array $applied = [];

    /** @var array<string, Money> the amount last applied per reference */
    protected array $amounts = [];

    public function markPaid(string $reference, Money $amount, PaymentResult $result): void
    {
        $this->applied[$reference] = ($this->applied[$reference] ?? 0) + 1;
        $this->amounts[$reference] = $amount;
    }

    /** How many times `$reference` was marked paid — exactly-once means this is 1. */
    public function timesPaid(string $reference): int
    {
        return $this->applied[$reference] ?? 0;
    }

    /** Whether `$reference` has been marked paid at least once. */
    public function isPaid(string $reference): bool
    {
        return $this->timesPaid($reference) > 0;
    }

    /** The amount last applied to `$reference`, or null if it was never applied. */
    public function amountPaid(string $reference): ?Money
    {
        return $this->amounts[$reference] ?? null;
    }
}
