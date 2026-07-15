<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Testing;

use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Invoice\DefaultInvoicer;
use Cbox\Billing\Invoice\Sequences\InMemoryInvoiceNumberSequence;

/**
 * Wire up the currency-locked invoicer in tests:
 *
 *     $invoicer = $this->makeInvoicer();
 *     $invoicer->issue($quote, $seller, 'acme', $at);   // stamps + locks 'acme' to $quote->currency
 *     expect($this->currencyLock()->lockedCurrency('acme'))->toBe('EUR');
 *
 * The invoicer is built over the shared {@see FakeBillingCurrencyLock} so a test can
 * inspect and pre-seed the lock, and dogfoods the in-memory number sequence.
 */
trait InteractsWithBillingCurrencyLock
{
    private ?FakeBillingCurrencyLock $currencyLockFake = null;

    protected function currencyLock(): FakeBillingCurrencyLock
    {
        return $this->currencyLockFake ??= new FakeBillingCurrencyLock;
    }

    protected function makeInvoicer(
        ?BillingCurrencyLock $lock = null,
        ?InvoiceNumberSequence $sequence = null,
    ): DefaultInvoicer {
        return new DefaultInvoicer(
            $sequence ?? new InMemoryInvoiceNumberSequence,
            $lock ?? $this->currencyLock(),
        );
    }
}
