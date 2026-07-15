<?php

declare(strict_types=1);

use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Dunning\Enums\DunningAction;
use Cbox\Billing\Payment\Dunning\Enums\InvoicePaymentState;
use Cbox\Billing\Payment\Dunning\Exceptions\InvalidDunningConfig;
use Cbox\Billing\Payment\Dunning\ValueObjects\DelinquentInvoice;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningConfig;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningState;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

/** A fixed reference instant; every due date is expressed relative to it. */
function dunningNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-03-01T12:00:00+00:00');
}

/** An open invoice whose due instant is `$daysAgo` days before {@see dunningNow()}. */
function openInvoiceDaysAgo(string $number, int $daysAgo): DelinquentInvoice
{
    return new DelinquentInvoice(
        number: $number,
        dueAt: dunningNow()->modify(sprintf('-%d days', $daysAgo)),
        state: InvoicePaymentState::Open,
        amountDue: Money::ofMinor(10000, 'EUR'),
    );
}

it('rejects a nonsensical knob set (deny-by-default)', function (): void {
    expect(fn () => new DunningConfig(maxDelinquencyDays: 0, minNoticeCount: 3, noticeFrequencyDays: 7))
        ->toThrow(InvalidDunningConfig::class);

    expect(fn () => new DunningConfig(maxDelinquencyDays: 30, minNoticeCount: -1, noticeFrequencyDays: 7))
        ->toThrow(InvalidDunningConfig::class);

    expect(fn () => new DunningConfig(maxDelinquencyDays: 30, minNoticeCount: 3, noticeFrequencyDays: 0))
        ->toThrow(InvalidDunningConfig::class);
});

it('ignores an invoice less than 24h past due — a just-missed payment is not dunned', function (): void {
    $justMissed = new DelinquentInvoice(
        'INV-1',
        dunningNow()->modify('-12 hours'),
        InvoicePaymentState::Open,
        Money::ofMinor(10000, 'EUR'),
    );

    $outcome = $this->dunningRunner()->run('acme', [$justMissed], dunningNow());

    expect($outcome->action)->toBe(DunningAction::NoOp)
        ->and($this->dunningStanding()->standingOf('acme'))->toBe(AccountStandingState::Good)
        ->and($this->dunningStateStore()->saves)->toBe([]);   // nothing recorded
});

it('sends the first reminder once an invoice is past the grace window', function (): void {
    $outcome = $this->dunningRunner()->run('acme', [openInvoiceDaysAgo('INV-1', 5)], dunningNow());

    expect($outcome->action)->toBe(DunningAction::SendNotice)
        ->and($outcome->noticeNumber)->toBe(1)
        ->and($outcome->delinquencyDays)->toBe(5)
        ->and($outcome->invoiceNumbers)->toBe(['INV-1'])
        ->and($this->dunningStateStore()->load('acme')->noticesSent)->toBe(1);
});

it('paces reminders by the notice-frequency cadence', function (): void {
    $invoices = [openInvoiceDaysAgo('INV-1', 5)];

    // First run sends notice 1.
    $this->dunningRunner()->run('acme', $invoices, dunningNow());

    // Same instant again → within cadence → no second notice.
    $again = $this->dunningRunner()->run('acme', $invoices, dunningNow());
    expect($again->action)->toBe(DunningAction::NoOp)
        ->and($this->dunningStateStore()->load('acme')->noticesSent)->toBe(1);

    // Seven days later → cadence elapsed → notice 2.
    $later = $this->dunningRunner()->run('acme', $invoices, dunningNow()->modify('+7 days'));
    expect($later->action)->toBe(DunningAction::SendNotice)
        ->and($later->noticeNumber)->toBe(2)
        ->and($this->dunningStateStore()->load('acme')->noticesSent)->toBe(2);
});

it('does NOT suspend past the day threshold until the minimum reminders have gone out', function (): void {
    // 40 days delinquent (>= 30) but zero notices sent (< min 3): it notices, not suspends.
    $outcome = $this->dunningRunner()->run('acme', [openInvoiceDaysAgo('INV-1', 40)], dunningNow());

    expect($outcome->action)->toBe(DunningAction::SendNotice)
        ->and($this->dunningStanding()->standingOf('acme'))->toBe(AccountStandingState::Good);
});

it('suspends once past the day threshold AND minimum reminders sent, gating access', function (): void {
    // Pre-seed three delivered reminders, last one over a week ago.
    $this->dunningStateStore()->save('acme', new DunningState(
        noticesSent: 3,
        lastNoticeAt: dunningNow()->modify('-8 days'),
    ));

    $outcome = $this->dunningRunner()->run('acme', [openInvoiceDaysAgo('INV-1', 40)], dunningNow());

    expect($outcome->action)->toBe(DunningAction::Suspend)
        ->and($outcome->newStanding)->toBe(AccountStandingState::Suspended)
        ->and($outcome->delinquencyDays)->toBe(40);

    // Standing now gates access.
    $standing = $this->dunningStanding();
    expect($standing->standingOf('acme'))->toBe(AccountStandingState::Suspended)
        ->and($standing->standingOf('acme')->grantsAccess())->toBeFalse()
        ->and($standing->transitions)->toHaveCount(1);
});

it('bypasses dunning entirely for an allow-listed account', function (): void {
    $this->dunningAllowList()->allow('vip');
    $this->dunningStateStore()->save('vip', new DunningState(3, dunningNow()->modify('-30 days')));

    // 40 days delinquent with the reminders sent would otherwise suspend — but it is bypassed.
    $outcome = $this->dunningRunner()->run('vip', [openInvoiceDaysAgo('INV-1', 40)], dunningNow());

    expect($outcome->action)->toBe(DunningAction::NoOp)
        ->and($outcome->reason)->toContain('allow-list')
        ->and($this->dunningStanding()->standingOf('vip'))->toBe(AccountStandingState::Good)
        ->and($this->dunningAllowList()->queried)->toContain('vip');   // the flag was consulted
});

it('restores a suspended account once all debt is cleared and none is written off', function (): void {
    $this->dunningStanding()->flag('acme', AccountStandingState::Suspended, 'dunning');

    $paid = new DelinquentInvoice('INV-1', dunningNow()->modify('-40 days'), InvoicePaymentState::Paid, Money::ofMinor(10000, 'EUR'));

    $outcome = $this->dunningRunner()->run('acme', [$paid], dunningNow());

    expect($outcome->action)->toBe(DunningAction::Restore)
        ->and($outcome->newStanding)->toBe(AccountStandingState::Good)
        ->and($this->dunningStanding()->standingOf('acme')->grantsAccess())->toBeTrue();
});

it('does NOT restore while any invoice is uncollectible, even after other debt is cleared', function (): void {
    $this->dunningStanding()->flag('acme', AccountStandingState::Suspended, 'dunning');

    $invoices = [
        new DelinquentInvoice('INV-1', dunningNow()->modify('-40 days'), InvoicePaymentState::Paid, Money::ofMinor(10000, 'EUR')),
        new DelinquentInvoice('INV-2', dunningNow()->modify('-90 days'), InvoicePaymentState::Uncollectible, Money::ofMinor(5000, 'EUR')),
    ];

    $outcome = $this->dunningRunner()->run('acme', $invoices, dunningNow());

    expect($outcome->action)->toBe(DunningAction::NoOp)
        ->and($outcome->reason)->toContain('written-off')
        ->and($this->dunningStanding()->standingOf('acme'))->toBe(AccountStandingState::Suspended)
        ->and($this->dunningStanding()->standingOf('acme')->grantsAccess())->toBeFalse();
});

it('holds a suspended account while open debt remains outstanding', function (): void {
    $this->dunningStanding()->flag('acme', AccountStandingState::Suspended, 'dunning');

    // One paid, one still open (any age) → debt not cleared → no restore.
    $invoices = [
        new DelinquentInvoice('INV-1', dunningNow()->modify('-40 days'), InvoicePaymentState::Paid, Money::ofMinor(10000, 'EUR')),
        openInvoiceDaysAgo('INV-2', 2),
    ];

    $outcome = $this->dunningRunner()->run('acme', $invoices, dunningNow());

    expect($outcome->action)->toBe(DunningAction::NoOp)
        ->and($this->dunningStanding()->standingOf('acme'))->toBe(AccountStandingState::Suspended);
});

it('defers to an open dispute rather than touching a disputed account', function (): void {
    $this->dunningStanding()->flag('acme', AccountStandingState::Disputed, 'chargeback:dp_1');

    $outcome = $this->dunningRunner()->run('acme', [openInvoiceDaysAgo('INV-1', 40)], dunningNow());

    expect($outcome->action)->toBe(DunningAction::NoOp)
        ->and($this->dunningStanding()->standingOf('acme'))->toBe(AccountStandingState::Disputed);   // dispute reason preserved
});

it('gates access on suspension WITHOUT touching credit balances', function (): void {
    // An account carrying granted credits.
    $apiCalls = Denomination::unit('api.calls');
    $this->wallet()->grant(new CreditGrant('inc', 'acme', Pools::included(), $apiCalls, 500, expiresAt: null, grantedAt: 1));

    $balanceBefore = $this->wallet()->balance('acme', Pools::included(), $apiCalls, now: 1_000);

    // Drive a full dunning suspension.
    $this->dunningStateStore()->save('acme', new DunningState(3, dunningNow()->modify('-8 days')));
    $outcome = $this->dunningRunner()->run('acme', [openInvoiceDaysAgo('INV-1', 40)], dunningNow());

    expect($outcome->action)->toBe(DunningAction::Suspend)
        ->and($this->dunningStanding()->standingOf('acme')->grantsAccess())->toBeFalse()
        // Access is gated, but the credit balance is untouched — money and access are separate seams.
        ->and($this->wallet()->balance('acme', Pools::included(), $apiCalls, now: 1_000))->toBe($balanceBefore)
        ->and($this->wallet()->balance('acme', Pools::included(), $apiCalls, now: 1_000))->toBe(500);
});
