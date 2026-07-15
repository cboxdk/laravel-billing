<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\Webhook\Storage\InMemoryInvoicePaymentApplier;
use RuntimeException;

/**
 * Test fake for the host invoice effect — the recording in-memory applier, plus
 * {@see FakeInvoicePaymentApplier::crashOnNextApply()} to simulate a host that dies while
 * persisting the effect. When armed, the next {@see FakeInvoicePaymentApplier::markPaid()}
 * throws BEFORE recording anything, standing in for a crash between "handler returned" and
 * "host persisted": nothing is written, exactly as a rolled-back transaction leaves it.
 */
class FakeInvoicePaymentApplier extends InMemoryInvoicePaymentApplier
{
    private bool $crashArmed = false;

    /** Arm a one-shot crash: the next markPaid() throws before recording anything. */
    public function crashOnNextApply(): void
    {
        $this->crashArmed = true;
    }

    public function markPaid(string $reference, Money $amount, PaymentResult $result): void
    {
        if ($this->crashArmed) {
            $this->crashArmed = false;

            throw new RuntimeException('Simulated host crash while persisting the settlement.');
        }

        parent::markPaid($reference, $amount, $result);
    }
}
