<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\DatabaseLedger;
use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\ValueObjects\LedgerLine;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = new DatabaseLedger($this->app->make('db')->connection());
});

function saleTransaction(string $id): LedgerTransaction
{
    return new LedgerTransaction($id, [
        new LedgerLine('receivable', Direction::Debit, Money::ofMinor(10000, 'EUR')),
        new LedgerLine('revenue', Direction::Credit, Money::ofMinor(10000, 'EUR')),
    ]);
}

it('posts a transaction and derives balances from durable rows', function () {
    $this->ledger->post(saleTransaction('tx_1'));

    expect($this->ledger->balance('receivable', 'EUR')->minor())->toBe(10000)   // debit-normal
        ->and($this->ledger->balance('revenue', 'EUR')->minor())->toBe(-10000)  // credit-normal
        ->and($this->ledger->balance('receivable', 'GBP')->minor())->toBe(0);   // other currency
});

it('is idempotent: re-posting the same transaction id does not double-count', function () {
    $this->ledger->post(saleTransaction('tx_1'));
    $this->ledger->post(saleTransaction('tx_1')); // retry

    expect($this->ledger->balance('receivable', 'EUR')->minor())->toBe(10000);
});

it('accumulates balances across transactions', function () {
    $this->ledger->post(saleTransaction('tx_1'));
    $this->ledger->post(saleTransaction('tx_2'));

    expect($this->ledger->balance('receivable', 'EUR')->minor())->toBe(20000);
});
