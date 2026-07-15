<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\Exceptions\InvalidTransfer;
use Cbox\Billing\Ledger\InMemoryTwoPhaseLedger;
use Cbox\Billing\Ledger\ValueObjects\LedgerLine;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;

function transfer(string $id, int $minor): LedgerTransaction
{
    return new LedgerTransaction($id, [
        new LedgerLine('ar', Direction::Debit, Money::ofMinor($minor, 'EUR')),
        new LedgerLine('rev', Direction::Credit, Money::ofMinor($minor, 'EUR')),
    ]);
}

beforeEach(fn () => $this->ledger = new InMemoryTwoPhaseLedger);

it('a reservation lowers available but not the posted balance', function () {
    $this->ledger->reserve(transfer('t1', 10000));

    expect($this->ledger->balance('ar', 'EUR')->minor())->toBe(0)
        ->and($this->ledger->available('ar', 'EUR')->minor())->toBe(10000);
});

it('commit turns a reservation into a posted balance', function () {
    $this->ledger->reserve(transfer('t1', 10000));
    $this->ledger->commit('t1');

    expect($this->ledger->balance('ar', 'EUR')->minor())->toBe(10000)
        ->and($this->ledger->available('ar', 'EUR')->minor())->toBe(10000);
});

it('release cancels a reservation, restoring available', function () {
    $this->ledger->reserve(transfer('t1', 10000));
    $this->ledger->reserve(transfer('t2', 5000));

    expect($this->ledger->available('ar', 'EUR')->minor())->toBe(15000);

    $this->ledger->release('t2');

    expect($this->ledger->available('ar', 'EUR')->minor())->toBe(10000)
        ->and($this->ledger->balance('ar', 'EUR')->minor())->toBe(0);
});

it('rejects a duplicate transfer id', function () {
    $this->ledger->reserve(transfer('t1', 10000));
    $this->ledger->reserve(transfer('t1', 10000));
})->throws(InvalidTransfer::class);

it('rejects committing a transfer that is not pending', function () {
    $this->ledger->reserve(transfer('t1', 10000));
    $this->ledger->commit('t1');
    $this->ledger->commit('t1'); // already posted
})->throws(InvalidTransfer::class);
