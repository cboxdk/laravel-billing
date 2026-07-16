<?php

declare(strict_types=1);

use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Events\InvoiceIssued;
use Cbox\Billing\Events\LicenseIssued;
use Cbox\Billing\Events\PaymentSettled;
use Cbox\Billing\Events\SubscriptionChanged;
use Cbox\Billing\Events\SubscriptionRenewed;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Invoice\Exceptions\CannotInvoicePendingQuote;
use Cbox\Billing\Invoice\Sequences\InMemoryCreditNoteNumberSequence;
use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Licensing\LicenseMint;
use Cbox\Billing\Licensing\ValueObjects\LicenseIssuanceRequest;
use Cbox\Billing\Licensing\ValueObjects\LicenseProfile;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\Webhook\DefaultWebhookIngest;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Refund\DefaultRefunder;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Refund\ValueObjects\RefundRequest;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\License\Capabilities;
use Cbox\License\Ed25519LicenseIssuer;
use Cbox\License\ValueObjects\LicenseLimits;
use Cbox\Tax\Enums\CustomerType;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

/**
 * The billing domain events dispatched at the engine's real lifecycle points, so plugins
 * and hosts register listeners rather than needing the host to call them. Each test issues
 * a REAL vector (a real invoice, a real settlement, a real license) and asserts the event
 * fires exactly once at its point with the aggregate it carries — and that an idempotent
 * replay does not double-fire.
 */
function eventSeller(): SellerEntity
{
    return new SellerEntity('dk', 'Cbox ApS', 'DK12345678', new CountryCode('DK'), 'DKK', 'DK', [
        new TaxRegistration(new CountryCode('DK'), 'DK12345678'),
    ]);
}

function issueRealInvoice(string $account = 'acme'): Invoice
{
    $seller = eventSeller();
    $place = app(JurisdictionRepository::class)->find(new CountryCode('DK'));
    $quote = app(QuoteBuilder::class)->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'EUR'))],
        new QuoteContext($place, CustomerType::Consumer, $seller->toSellerRegistrations()),
    );

    return app(Invoicer::class)->issue($quote, $seller, $account, new DateTimeImmutable('2025-09-01'));
}

it('dispatches InvoiceIssued exactly once when a real invoice is finalized', function (): void {
    Event::fake();

    $invoice = issueRealInvoice('acme');

    Event::assertDispatchedTimes(InvoiceIssued::class, 1);
    Event::assertDispatched(InvoiceIssued::class, function (InvoiceIssued $event) use ($invoice): bool {
        return $event->invoice === $invoice
            && $event->invoice->number === 'DK-000001'
            && $event->account === 'acme';
    });
});

it('does not dispatch InvoiceIssued when finalization is refused', function (): void {
    Event::fake();

    // A US-without-state quote is tax-pending, so the invoicer refuses it before drawing a
    // number — the guard throws and no event fires.
    $usSeller = new SellerEntity('us', 'Cbox Inc', '99-1234567', new CountryCode('US'), 'USD', 'US');
    $quote = app(QuoteBuilder::class)->build(
        [new LineInput('Pro plan', 1, Money::ofMinor(10000, 'USD'))],
        new QuoteContext(app(JurisdictionRepository::class)->find(new CountryCode('US')), CustomerType::Consumer, $usSeller->toSellerRegistrations()),
    );

    expect($quote->isTaxResolved())->toBeFalse();
    expect(fn () => app(Invoicer::class)->issue($quote, $usSeller, 'acme', new DateTimeImmutable('2025-09-01')))
        ->toThrow(CannotInvoicePendingQuote::class);

    Event::assertNotDispatched(InvoiceIssued::class);
});

it('dispatches PaymentSettled exactly once on the applying webhook and not on a re-delivery', function (): void {
    Event::fake();

    $ingest = new DefaultWebhookIngest(
        $this->webhookProcessed(),
        $this->webhookSettled(),
        $this->webhookApplier(),
        app(Dispatcher::class),
    );

    $event = new WebhookEvent('evt_1', WebhookEventType::PaymentSettled, 'DK-000001', Money::ofMinor(12500, 'EUR'));

    $ingest->ingest($event);
    $ingest->ingest($event); // re-delivered: already settled → no-op, must not re-fire

    Event::assertDispatchedTimes(PaymentSettled::class, 1);
    Event::assertDispatched(PaymentSettled::class, function (PaymentSettled $e): bool {
        return $e->reference === 'DK-000001'
            && $e->amount->minor() === 12500
            && $e->result->gatewayReference === 'evt_1';
    });
});

it('dispatches CreditNoteIssued exactly once per refund and not on an idempotent replay', function (): void {
    Event::fake();

    $invoice = issueRealInvoice('acme');

    $refunder = new DefaultRefunder(
        new InMemoryCreditNoteNumberSequence,
        $this->refundRepository(),
        $this->refundLedger(),
        $this->refundGateway(),
        $this->refundWallet(),
        app(Dispatcher::class),
    );

    $request = RefundRequest::full(
        'rf_1', 'acme', $invoice, RefundReason::Requested, new DateTimeImmutable('2025-09-05'),
    );

    $refunder->refund($request);
    $refunder->refund($request); // same id → idempotent, returns the issued refund, no re-fire

    Event::assertDispatchedTimes(CreditNoteIssued::class, 1);
    Event::assertDispatched(CreditNoteIssued::class, function (CreditNoteIssued $e): bool {
        return $e->creditNote->number === 'DK-CN-000001'
            && $e->creditNote->invoiceNumber === 'DK-000001'
            && $e->creditNote->account === 'acme'
            && $e->creditNote->gross->minor() === -12500;
    });
});

it('dispatches LicenseIssued for every mint, including a reissue', function (): void {
    Event::fake();

    $mint = new LicenseMint(
        new Ed25519LicenseIssuer($this->licensingKeyPair()['privateKey']),
        app(Dispatcher::class),
    );

    $request = new LicenseIssuanceRequest(
        customerId: 'cus_acme',
        deploymentId: 'dep_acme_prod',
        profile: new LicenseProfile(
            plan: 'enterprise',
            entitlements: [Capabilities::SSO],
            limits: new LicenseLimits(organizations: 25, seats: 500, environments: 5),
        ),
        notBefore: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        expiresAt: new DateTimeImmutable('2027-01-01T00:00:00Z'),
        licensedDomain: 'acme.example',
    );

    $issued = $mint->issue($request, new DateTimeImmutable('2026-01-01T00:00:00Z'));

    Event::assertDispatchedTimes(LicenseIssued::class, 1);
    Event::assertDispatched(LicenseIssued::class, fn (LicenseIssued $e): bool => $e->license === $issued);

    // A reissue mints a fresh, independently-revocable license → a second event.
    $mint->reissue($issued, new DateTimeImmutable('2028-01-01T00:00:00Z'), new DateTimeImmutable('2026-12-01T00:00:00Z'));

    Event::assertDispatchedTimes(LicenseIssued::class, 2);
});

it('dispatches SubscriptionRenewed on a renewal but not on a due cancellation', function (): void {
    Event::fake();

    $manager = new SubscriptionManager(app(Dispatcher::class));

    $period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $next = new BillingPeriod(new DateTimeImmutable('2025-10-01'), new DateTimeImmutable('2025-11-01'));

    $sub = $manager->create('sub_1', 'org_1', 'prod_pro', 'price_pro', $period);
    $renewed = $manager->renew($sub, $next);

    Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
    Event::assertDispatched(SubscriptionRenewed::class, function (SubscriptionRenewed $e) use ($sub, $renewed): bool {
        return $e->previous === $sub
            && $e->subscription === $renewed
            && $e->subscription->period->start->format('Y-m-d') === '2025-10-01';
    });

    // A subscription set to cancel at period end ends on renew — not a renewal, no event.
    $canceling = $manager->cancelAtPeriodEnd($sub);
    $manager->renew($canceling, $next);

    Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
});

it('dispatches SubscriptionChanged when a plan change is scheduled', function (): void {
    Event::fake();

    $manager = new SubscriptionManager(app(Dispatcher::class));

    $period = new BillingPeriod(new DateTimeImmutable('2025-09-01'), new DateTimeImmutable('2025-10-01'));
    $sub = $manager->create('sub_1', 'org_1', 'prod_pro', 'price_pro', $period);

    $changed = $manager->scheduleChange($sub, 'price_pro_v2', new DateTimeImmutable('2025-10-01'));

    Event::assertDispatchedTimes(SubscriptionChanged::class, 1);
    Event::assertDispatched(SubscriptionChanged::class, function (SubscriptionChanged $e) use ($changed): bool {
        return $e->subscription === $changed
            && $e->change->newPriceId === 'price_pro_v2'
            && $e->change->effectiveAt->format('Y-m-d') === '2025-10-01';
    });
});
