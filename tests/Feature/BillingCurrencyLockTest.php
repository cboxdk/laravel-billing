<?php

declare(strict_types=1);

use Cbox\Billing\Account\CurrencyLock\DatabaseBillingCurrencyLock;
use Cbox\Billing\Account\Exceptions\BillingCurrencyMismatch;
use Cbox\Billing\Invoice\DefaultInvoicer;
use Cbox\Billing\Invoice\Sequences\InMemoryInvoiceNumberSequence;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;
use Illuminate\Foundation\Testing\RefreshDatabase;

function lockSeller(): SellerEntity
{
    return new SellerEntity('dk', 'Cbox ApS', 'DK12345678', new CountryCode('DK'), 'DKK', 'DK', [
        new TaxRegistration(new CountryCode('DK'), 'DK12345678'),
    ]);
}

/** A tax-resolved DK quote whose currency is the first line's currency. */
function resolvedQuoteIn(string $currency): Quote
{
    $place = app(JurisdictionRepository::class)->find(new CountryCode('DK'));

    return app(QuoteBuilder::class)->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, $currency))],
        new QuoteContext($place, CustomerType::Consumer, lockSeller()->toSellerRegistrations()),
    );
}

it('stamps and locks the account currency on the first finalized invoice', function (): void {
    $invoicer = $this->makeInvoicer();

    expect($this->currencyLock()->lockedCurrency('acme'))->toBeNull(); // deny-by-default: unlocked

    $invoice = $invoicer->issue(resolvedQuoteIn('EUR'), lockSeller(), 'acme', new DateTimeImmutable('2025-09-01'));

    expect($invoice->currency)->toBe('EUR')
        ->and($this->currencyLock()->lockedCurrency('acme'))->toBe('EUR')
        ->and($this->currencyLock()->finalizations())->toBe(1);
});

it('finalizes a later invoice in the same currency', function (): void {
    $invoicer = $this->makeInvoicer();

    $first = $invoicer->issue(resolvedQuoteIn('EUR'), lockSeller(), 'acme', new DateTimeImmutable('2025-09-01'));
    $second = $invoicer->issue(resolvedQuoteIn('EUR'), lockSeller(), 'acme', new DateTimeImmutable('2025-10-01'));

    expect($first->number)->toBe('DK-000001')
        ->and($second->number)->toBe('DK-000002')  // finalization actually ran
        ->and($this->currencyLock()->lockedCurrency('acme'))->toBe('EUR')
        ->and($this->currencyLock()->finalizations())->toBe(2);
});

it('refuses a later invoice in a different currency and finalizes nothing', function (): void {
    $invoicer = $this->makeInvoicer();

    $invoicer->issue(resolvedQuoteIn('EUR'), lockSeller(), 'acme', new DateTimeImmutable('2025-09-01'));

    try {
        $invoicer->issue(resolvedQuoteIn('USD'), lockSeller(), 'acme', new DateTimeImmutable('2025-10-01'));
        $this->fail('Expected a currency mismatch to be thrown.');
    } catch (BillingCurrencyMismatch $e) {
        expect($e->account)->toBe('acme')
            ->and($e->lockedCurrency)->toBe('EUR')
            ->and($e->attemptedCurrency)->toBe('USD');
    }

    expect($this->currencyLock()->lockedCurrency('acme'))->toBe('EUR') // unchanged, one-way
        ->and($this->currencyLock()->finalizations())->toBe(1);        // the refused issue ran nothing
});

it('keeps the lock independent of any payment method', function (): void {
    // This seam is keyed on the billing account alone — it exposes no payment-method
    // surface at all, so nothing about adding or removing a card can read or clear it.
    $invoicer = $this->makeInvoicer();

    $invoicer->issue(resolvedQuoteIn('EUR'), lockSeller(), 'acme', new DateTimeImmutable('2025-09-01'));

    // Whatever a host does to payment methods in between, the lock is untouched.
    expect($this->currencyLock()->lockedCurrency('acme'))->toBe('EUR');

    // A later invoice must still honour it.
    expect(fn () => $invoicer->issue(resolvedQuoteIn('USD'), lockSeller(), 'acme', new DateTimeImmutable('2025-12-01')))
        ->toThrow(BillingCurrencyMismatch::class);
});

it('resolves a concurrent first-finalize to one currency via the persisted stamp', function (): void {
    // A concurrent first-finalize is modelled by re-entering the guard for the same
    // account from within the first finalizer: the first call has already stamped EUR
    // against persisted state, so the second (USD) sees that stamp and is refused —
    // exactly how a real race resolves to a single currency.
    $lock = $this->currencyLock();
    $invoicer = $this->makeInvoicer($lock);

    $race = fn (): mixed => $lock->stampAndGuard(
        'acme',
        'EUR',
        fn (): mixed => $invoicer->issue(resolvedQuoteIn('USD'), lockSeller(), 'acme', new DateTimeImmutable('2025-09-01')),
    );

    expect($race)->toThrow(BillingCurrencyMismatch::class)
        ->and($lock->lockedCurrency('acme'))->toBe('EUR');
});

it('reads a pre-persisted lock (a concurrent winner) as the source of truth', function (): void {
    // lockTo() stands in for another process having stamped USD first.
    $lock = $this->currencyLock()->lockTo('acme', 'USD');
    $invoicer = $this->makeInvoicer($lock);

    expect(fn () => $invoicer->issue(resolvedQuoteIn('EUR'), lockSeller(), 'acme', new DateTimeImmutable('2025-09-01')))
        ->toThrow(BillingCurrencyMismatch::class)
        ->and($lock->finalizations())->toBe(0); // the losing writer finalized nothing
});

describe('durable store', function (): void {
    uses(RefreshDatabase::class);

    it('persists the stamp and enforces the lock against the database row', function (): void {
        $db = $this->app->make('db')->connection();
        $lock = new DatabaseBillingCurrencyLock($db);
        $invoicer = new DefaultInvoicer(new InMemoryInvoiceNumberSequence, $lock);

        $invoicer->issue(resolvedQuoteIn('EUR'), lockSeller(), 'acme', new DateTimeImmutable('2025-09-01'));

        expect($lock->lockedCurrency('acme'))->toBe('EUR')
            ->and($db->table('billing_account_currency_locks')->where('account', 'acme')->count())->toBe(1);

        // A later invoice in a different currency is refused, reading the persisted row.
        expect(fn () => $invoicer->issue(resolvedQuoteIn('USD'), lockSeller(), 'acme', new DateTimeImmutable('2025-10-01')))
            ->toThrow(BillingCurrencyMismatch::class);
    });

    it('rolls the stamp back with the invoice when finalization fails', function (): void {
        $db = $this->app->make('db')->connection();
        $lock = new DatabaseBillingCurrencyLock($db);

        // A finalizer that throws mid-flight must not leave an orphan lock behind, so the
        // account is not silently pinned to a currency by a failed first invoice.
        expect(fn () => $lock->stampAndGuard('acme', 'EUR', function (): void {
            throw new RuntimeException('number sequence unavailable');
        }))->toThrow(RuntimeException::class);

        expect($lock->lockedCurrency('acme'))->toBeNull()
            ->and($db->table('billing_account_currency_locks')->count())->toBe(0);
    });
});
