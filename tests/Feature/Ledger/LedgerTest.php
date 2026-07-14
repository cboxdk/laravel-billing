<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\Exceptions\UnbalancedTransaction;
use Cbox\Billing\Ledger\InMemoryLedger;
use Cbox\Billing\Ledger\ValueObjects\LedgerLine;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;

function eur(int $minor): Money
{
    return Money::ofMinor($minor, 'EUR');
}

it('posts a balanced transaction and derives account balances from postings', function (): void {
    $ledger = new InMemoryLedger;

    // A customer tops up €50 of prepaid credit: debit cash, credit the wallet.
    $ledger->post(new LedgerTransaction('t1', [
        new LedgerLine('cash', Direction::Debit, eur(5000)),
        new LedgerLine('wallet:org_a:prepaid', Direction::Credit, eur(5000)),
    ]));

    // Debit-normal cash reads +; credit-normal wallet reads − (a liability we owe).
    expect($ledger->balance('cash', 'EUR')->minor())->toBe(5000)
        ->and($ledger->balance('wallet:org_a:prepaid', 'EUR')->minor())->toBe(-5000)
        ->and($ledger->balance('unknown', 'EUR')->minor())->toBe(0);
});

it('refuses an unbalanced transaction', function (): void {
    expect(fn () => new LedgerTransaction('t', [
        new LedgerLine('a', Direction::Debit, eur(100)),
        new LedgerLine('b', Direction::Credit, eur(90)),
    ]))->toThrow(UnbalancedTransaction::class);
});

it('refuses a mixed-currency transaction', function (): void {
    expect(fn () => new LedgerTransaction('t', [
        new LedgerLine('a', Direction::Debit, eur(100)),
        new LedgerLine('b', Direction::Credit, Money::ofMinor(100, 'USD')),
    ]))->toThrow(UnbalancedTransaction::class);
});
