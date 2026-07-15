<?php

declare(strict_types=1);

use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Refund\Enums\ReversalKind;
use Cbox\Billing\Refund\ValueObjects\ChargebackNotice;
use Cbox\Billing\Refund\ValueObjects\RefundRequest;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;

beforeEach(function () {
    $geo = $this->app->make(JurisdictionRepository::class);
    $this->seller = new SellerEntity('dk', 'Cbox ApS', 'DK12345678', new CountryCode('DK'), 'DKK', 'DK', [
        new TaxRegistration(new CountryCode('DK'), 'DK12345678'),
    ]);

    $quote = $this->app->make(QuoteBuilder::class)->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'EUR'))],
        new QuoteContext($geo->find(new CountryCode('DK')), CustomerType::Consumer, $this->seller->toSellerRegistrations()),
    );

    $this->invoice = $this->app->make(Invoicer::class)
        ->issue($quote, $this->seller, 'acme', new DateTimeImmutable('2025-09-01'));
});

function disputeNotice(Invoice $invoice): ChargebackNotice
{
    return ChargebackNotice::forInvoice('dp_1', 'acme', $invoice, 'fraudulent', new DateTimeImmutable('2025-09-10'));
}

it('records the dispute, posts the reversal, and flags the account disputed', function () {
    $chargeback = $this->chargebackHandler()->handle(disputeNotice($this->invoice));

    expect($chargeback->gross->minor())->toBe(12500)
        ->and($chargeback->kind)->toBe(ReversalKind::Forced)          // forced, not voluntary
        ->and($chargeback->standingApplied)->toBe(AccountStandingState::Disputed)
        ->and($chargeback->reason)->toBe('fraudulent');

    // The reversal hit the ledger, mirroring the sale's tax too.
    expect($this->refundLedger()->balance('receivable:acme', 'EUR')->minor())->toBe(-12500)
        ->and($this->refundLedger()->balance('tax:dk', 'EUR')->minor())->toBe(2500);

    // Account standing moved to gate access.
    $standing = $this->refundStanding();
    expect($standing->standingOf('acme'))->toBe(AccountStandingState::Disputed)
        ->and($standing->standingOf('acme')->grantsAccess())->toBeFalse();
});

it('is idempotent on the dispute reference: a re-delivered notice is a no-op', function () {
    $first = $this->chargebackHandler()->handle(disputeNotice($this->invoice));
    $second = $this->chargebackHandler()->handle(disputeNotice($this->invoice));

    expect($second->ledgerTransactionId)->toBe($first->ledgerTransactionId)
        ->and($this->refundLedger()->balance('receivable:acme', 'EUR')->minor())->toBe(-12500) // not doubled
        ->and($this->refundStanding()->transitions)->toHaveCount(1);                            // flagged once
});

it('distinguishes a chargeback from a voluntary refund in the ledger', function () {
    // A voluntary refund and a forced chargeback post under different sources, so they
    // are not deduped against each other — both land, and both are auditable apart.
    $this->refunder()->refund(RefundRequest::partial(
        'rf_1', 'acme', $this->invoice, Money::ofMinor(4000, 'EUR'), RefundReason::Goodwill, new DateTimeImmutable('2025-09-05'),
    ));

    $this->chargebackHandler()->handle(ChargebackNotice::forInvoice(
        'dp_1', 'acme', $this->invoice, 'product_not_received', new DateTimeImmutable('2025-09-10'),
    ));

    // Refund credited receivable 5000, chargeback credited 12500 → -17500 total.
    expect($this->refundLedger()->balance('receivable:acme', 'EUR')->minor())->toBe(-17500);
});
