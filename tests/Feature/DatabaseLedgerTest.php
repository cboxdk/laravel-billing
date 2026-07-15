<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\DatabaseLedger;
use Cbox\Billing\Ledger\Enums\Direction;
use Cbox\Billing\Ledger\ValueObjects\LedgerLine;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;
use Cbox\Billing\Ledger\ValueObjects\PostingKey;
use Cbox\Billing\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->db = $this->app->make('db')->connection();
    $this->durableLedger = new DatabaseLedger($this->db);
});

function saleTransaction(string $id, ?PostingKey $key = null): LedgerTransaction
{
    return new LedgerTransaction($id, [
        new LedgerLine('receivable', Direction::Debit, Money::ofMinor(10000, 'EUR')),
        new LedgerLine('revenue', Direction::Credit, Money::ofMinor(10000, 'EUR')),
    ], key: $key);
}

it('posts a transaction and derives balances from durable rows', function () {
    $this->durableLedger->post(saleTransaction('tx_1'));

    expect($this->durableLedger->balance('receivable', 'EUR')->minor())->toBe(10000)   // debit-normal
        ->and($this->durableLedger->balance('revenue', 'EUR')->minor())->toBe(-10000)  // credit-normal
        ->and($this->durableLedger->balance('receivable', 'GBP')->minor())->toBe(0);   // other currency
});

it('is idempotent on the transaction id when no natural key is given', function () {
    $this->durableLedger->post(saleTransaction('tx_1'));
    $this->durableLedger->post(saleTransaction('tx_1')); // retry

    expect($this->durableLedger->balance('receivable', 'EUR')->minor())->toBe(10000)
        ->and($this->db->table('billing_ledger_lines')->count())->toBe(2);
});

it('is idempotent on the application natural key, even across different transaction ids', function () {
    // Same (org, source, reference) posted twice under different technical ids — e.g. a
    // retry that regenerated the ULID. The natural key, not the id, is the dedupe key.
    $key = new PostingKey('org_a', 'invoice', 'inv_1');

    $this->durableLedger->post(saleTransaction('tx_a', $key));
    $this->durableLedger->post(saleTransaction('tx_b', $key)); // reprocess — no-op

    expect($this->durableLedger->balance('receivable', 'EUR')->minor())->toBe(10000)
        ->and($this->db->table('billing_ledger_lines')->count())->toBe(2)
        ->and($this->db->table('billing_ledger_postings')->count())->toBe(1);
});

it('records exactly one posting register row per natural key', function () {
    $this->durableLedger->post(saleTransaction('tx_1', new PostingKey('org_a', 'wallet', 'draw_1')));
    $this->durableLedger->post(saleTransaction('tx_2', new PostingKey('org_a', 'wallet', 'draw_2')));

    $rows = $this->db->table('billing_ledger_postings')->orderBy('reference')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->source)->toBe('wallet')
        ->and($rows[0]->reference)->toBe('draw_1')
        ->and($rows[0]->transaction_id)->toBe('tx_1');
});

it('accumulates balances across distinct transactions', function () {
    $this->durableLedger->post(saleTransaction('tx_1'));
    $this->durableLedger->post(saleTransaction('tx_2'));

    expect($this->durableLedger->balance('receivable', 'EUR')->minor())->toBe(20000);
});

it('carries no unique index on the partition-bound ledger-lines table', function () {
    // ADR-0002: idempotency lives in the separate unpartitioned register, never a
    // UNIQUE index on the ledger lines (which would become illegal once partitioned).
    // Proof: two rows with the SAME transaction_id coexist — a UNIQUE(transaction_id)
    // would reject the second.
    $this->db->table('billing_ledger_lines')->insert([
        ['transaction_id' => 'dup', 'account' => 'a', 'direction' => 'debit', 'amount_minor' => 1, 'currency' => 'EUR'],
        ['transaction_id' => 'dup', 'account' => 'b', 'direction' => 'credit', 'amount_minor' => 1, 'currency' => 'EUR'],
    ]);

    expect($this->db->table('billing_ledger_lines')->where('transaction_id', 'dup')->count())->toBe(2);
});
