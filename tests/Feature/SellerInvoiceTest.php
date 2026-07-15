<?php

declare(strict_types=1);

use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\Exceptions\CannotInvoicePendingQuote;
use Cbox\Billing\Invoice\Sequences\InMemoryInvoiceNumberSequence;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Seller\DefaultEntityRouter;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Enums\CustomerType;

function dkEntity(): SellerEntity
{
    return new SellerEntity('dk', 'Cbox ApS', 'DK12345678', new CountryCode('DK'), 'DKK', 'DK', [
        new TaxRegistration(new CountryCode('DK'), 'DK12345678'),
    ]);
}

function usEntity(): SellerEntity
{
    return new SellerEntity('us', 'Cbox Inc', '99-1234567', new CountryCode('US'), 'USD', 'US');
}

beforeEach(function () {
    $this->geo = $this->app->make(JurisdictionRepository::class);
});

it('routes to the entity registered in the buyer country, else the default', function () {
    $router = new DefaultEntityRouter([dkEntity(), usEntity()], dkEntity());

    expect($router->routeFor($this->geo->find(new CountryCode('DK')))->id)->toBe('dk')
        ->and($router->routeFor($this->geo->find(new CountryCode('US')))->id)->toBe('us')
        ->and($router->routeFor($this->geo->find(new CountryCode('FR')))->id)->toBe('dk'); // default
});

it('maps a selling entity to tax seller-registrations', function () {
    $registrations = dkEntity()->toSellerRegistrations();

    expect($registrations->establishment->value)->toBe('DK')
        ->and($registrations->isRegisteredIn(new CountryCode('DK')))->toBeTrue();
});

it('numbers invoices per entity, monotonic and gapless', function () {
    $sequence = new InMemoryInvoiceNumberSequence;

    expect($sequence->next(dkEntity()))->toBe('DK-000001')
        ->and($sequence->next(dkEntity()))->toBe('DK-000002')
        ->and($sequence->next(usEntity()))->toBe('US-000001'); // separate sequence
});

it('issues an invoice from a confirmed quote, end to end', function () {
    $router = new DefaultEntityRouter([dkEntity(), usEntity()], dkEntity());
    $builder = $this->app->make(QuoteBuilder::class);
    $invoicer = $this->app->make(Invoicer::class);

    $place = $this->geo->find(new CountryCode('DK'));
    $entity = $router->routeFor($place);

    $quote = $builder->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'EUR'))],
        new QuoteContext($place, CustomerType::Consumer, $entity->toSellerRegistrations()),
    );

    $invoice = $invoicer->issue($quote, $entity, new DateTimeImmutable('2025-09-01'));

    expect($invoice->number)->toBe('DK-000001')
        ->and($invoice->seller->legalName)->toBe('Cbox ApS')
        ->and($invoice->totals->gross->minor())->toBe(12500)   // 100 + 25% DK VAT
        ->and($invoice->lines)->toHaveCount(1);
});

it('refuses to invoice a tax-pending quote', function () {
    $builder = $this->app->make(QuoteBuilder::class);
    $invoicer = $this->app->make(Invoicer::class);

    $quote = $builder->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'USD'))],
        new QuoteContext($this->geo->find(new CountryCode('US')), CustomerType::Consumer, usEntity()->toSellerRegistrations()),
    );

    $invoicer->issue($quote, usEntity(), new DateTimeImmutable('2025-09-01'));
})->throws(CannotInvoicePendingQuote::class);
