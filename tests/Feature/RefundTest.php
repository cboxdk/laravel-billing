<?php

declare(strict_types=1);

use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Quote\ValueObjects\QuoteTotals;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Refund\Exceptions\CannotRefund;
use Cbox\Billing\Refund\ValueObjects\RefundRequest;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
    $this->seller = new SellerEntity('dk', 'Cbox ApS', 'DK12345678', new CountryCode('DK'), 'DKK', 'DK', [
        new TaxRegistration(new CountryCode('DK'), 'DK12345678'),
    ]);

    $place = $this->geo->find(new CountryCode('DK'));
    $quote = $this->app->make(QuoteBuilder::class)->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'EUR'))],
        new QuoteContext($place, CustomerType::Consumer, $this->seller->toSellerRegistrations()),
    );

    // net 10000 + 25% DK VAT → tax 2500, gross 12500.
    $this->invoice = $this->app->make(Invoicer::class)
        ->issue($quote, $this->seller, 'acme', new DateTimeImmutable('2025-09-01'));
});

function fullRefund(string $id, Invoice $invoice): RefundRequest
{
    return RefundRequest::full($id, 'acme', $invoice, RefundReason::Requested, new DateTimeImmutable('2025-09-05'));
}

it('issues a full refund: credit note off its own sequence, reversing tax, and posts the reversal', function () {
    $refund = $this->refunder()->refund(fullRefund('rf_1', $this->invoice));

    expect($refund->gross->minor())->toBe(12500)
        ->and($refund->kind)->toBe(ReversalKind::Voluntary)
        ->and($refund->creditNote->number)->toBe('DK-CN-000001')          // separate legal sequence
        ->and($refund->creditNote->invoiceNumber)->toBe('DK-000001')       // references the original
        ->and($refund->creditNote->net->minor())->toBe(-10000)             // mirrored negatively
        ->and($refund->creditNote->tax->minor())->toBe(-2500)              // tax reversed too
        ->and($refund->creditNote->gross->minor())->toBe(-12500)
        ->and($refund->creditNote->lines)->toHaveCount(1);

    // The reversing posting: receivable credited (debit-normal → reads negative),
    // revenue and tax debited back out.
    expect($this->refundLedger()->balance('receivable:acme', 'EUR')->minor())->toBe(-12500)
        ->and($this->refundLedger()->balance('revenue:dk', 'EUR')->minor())->toBe(10000)
        ->and($this->refundLedger()->balance('tax:dk', 'EUR')->minor())->toBe(2500);
});

it('moves the money through the payment gateway with a scoped idempotency key', function () {
    $this->refunder()->refund(fullRefund('rf_1', $this->invoice));

    $gateway = $this->refundGateway();

    expect($gateway->refunded)->toHaveCount(1)
        ->and($gateway->refunded[0]->amount->minor())->toBe(12500)
        ->and($gateway->refunded[0]->idempotencyKey)->toBe('refund:rf_1')
        ->and($gateway->refunded[0]->reference)->toBe('DK-CN-000001');
});

it('reverses tax proportionally on a partial refund', function () {
    $refund = $this->refunder()->refund(RefundRequest::partial(
        'rf_2', 'acme', $this->invoice, Money::ofMinor(4000, 'EUR'), RefundReason::ServiceIssue, new DateTimeImmutable('2025-09-05'),
    ));

    // 2500 tax * 4000/10000 = 1000; gross 5000.
    expect($refund->gross->minor())->toBe(5000)
        ->and($refund->creditNote->net->minor())->toBe(-4000)
        ->and($refund->creditNote->tax->minor())->toBe(-1000)
        ->and($refund->creditNote->gross->minor())->toBe(-5000)
        ->and($this->refundLedger()->balance('receivable:acme', 'EUR')->minor())->toBe(-5000);
});

it('refuses to refund more than was charged', function () {
    $this->refunder()->refund(fullRefund('rf_1', $this->invoice)); // full 12500

    $this->refunder()->refund(fullRefund('rf_2', $this->invoice)); // would total 25000
})->throws(CannotRefund::class);

it('refuses to refund an unissued invoice', function () {
    $place = $this->geo->find(new CountryCode('DK'));
    $zero = Money::zero('EUR');
    $unissued = new Invoice('', $this->seller, $place, 'EUR', [], new QuoteTotals($zero, $zero, $zero, $zero, $zero), new DateTimeImmutable('2025-09-01'));

    $this->refunder()->refund(fullRefund('rf_x', $unissued));
})->throws(CannotRefund::class);

it('is idempotent: a re-refund on the same request id is a no-op', function () {
    $first = $this->refunder()->refund(fullRefund('rf_1', $this->invoice));
    $second = $this->refunder()->refund(fullRefund('rf_1', $this->invoice));

    expect($second->creditNote->number)->toBe($first->creditNote->number) // no second number burned
        ->and($this->refundGateway()->refunded)->toHaveCount(1)            // no second money movement
        ->and($this->refundLedger()->balance('receivable:acme', 'EUR')->minor())->toBe(-12500); // not doubled
});

it('reverses a purchase-issued credit grant with an offsetting grant, not a balance edit', function () {
    $pool = new Pool('prepaid', spendable: true, mayGoNegative: false, forfeitsOnCancel: false, requiresExpiry: false, reportable: true);
    $denomination = Denomination::money('EUR');
    $grant = new CreditGrant('g_1', 'acme', $pool, $denomination, remaining: 5000, expiresAt: null);

    $wallet = $this->refundWallet();
    $wallet->grant($grant);

    $refund = $this->refunder()->refund(fullRefund('rf_1', $this->invoice)->reversingGrant($grant));

    expect($refund->grantReversalId)->toBe('refund:rf_1:grant-reversal')
        ->and($wallet->balance('acme', $pool, $denomination, now: 0))->toBe(0); // 5000 + (-5000), original lot untouched
});

it('records the gateway settlement on the refund', function () {
    $refund = $this->refunder()->refund(fullRefund('rf_1', $this->invoice));

    expect($refund->gatewayResult->status)->toBe(PaymentStatus::Succeeded);
});
