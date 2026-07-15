<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\Testing;

use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\InMemoryLedger;
use Cbox\Billing\Ledger\InMemoryTwoPhaseLedger;
use Cbox\Billing\Ledger\ValueObjects\LedgerLine;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Ledger\ValueObjects\PostingKey;
use Cbox\Billing\Money\Money;

/**
 * Wire up a ledger in tests with the in-memory fakes:
 *
 *     $ledger = $this->ledger();
 *     $ledger->post($this->transfer('acq', 'revenue', 10_00, key: $this->postingKey('org_a', 'invoice', 'inv_1')));
 *     expect($ledger->balance('acq', 'EUR')->minor())->toBe(1000);
 *
 * The fakes honour the same contract as the durable ledger, including ADR-0002
 * idempotency: re-posting the same natural key is a no-op. `transfer()` builds a
 * balanced two-line transaction so tests never hand-roll the double-entry boilerplate.
 */
trait InteractsWithLedger
{
    private ?InMemoryLedger $ledgerFake = null;

    private ?InMemoryTwoPhaseLedger $twoPhaseLedgerFake = null;

    protected function ledger(): InMemoryLedger
    {
        return $this->ledgerFake ??= new InMemoryLedger;
    }

    protected function twoPhaseLedger(): InMemoryTwoPhaseLedger
    {
        return $this->twoPhaseLedgerFake ??= new InMemoryTwoPhaseLedger;
    }

    protected function postingKey(string $org, string $source, string $reference): PostingKey
    {
        return new PostingKey($org, $source, $reference);
    }

    /** A balanced debit→credit transfer of `minor` units between two accounts. */
    protected function transfer(
        string $debit,
        string $credit,
        int $minor,
        string $currency = 'EUR',
        string $id = 'tx',
        ?PostingKey $key = null,
    ): LedgerTransaction {
        return new LedgerTransaction($id, [
            new LedgerLine($debit, Direction::Debit, Money::ofMinor($minor, $currency)),
            new LedgerLine($credit, Direction::Credit, Money::ofMinor($minor, $currency)),
        ], key: $key);
    }
}
