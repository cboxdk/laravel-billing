<?php

declare(strict_types=1);

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Ledger\ValueObjects\LedgerTransaction;

it('exposes no API to update or delete a posted transaction', function () {
    // Append-only at the layer the package fully controls: the only mutation the
    // contract offers is post(). No update/edit/delete/reverse/void mutator exists,
    // so a posted transaction cannot be changed through the ledger API — corrections
    // are new reversing transactions. (DB-level triggers harden this further on
    // MySQL via the immutability migration.)
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(Ledger::class))->getMethods(),
    );

    expect($methods)->toBe(['post', 'balance']);

    foreach (['update', 'edit', 'delete', 'remove', 'void', 'reverse', 'mutate'] as $forbidden) {
        expect(method_exists(Ledger::class, $forbidden))->toBeFalse();
    }
});

it('makes posted lines immutable value objects', function () {
    expect((new ReflectionClass(LedgerTransaction::class))->isReadOnly())->toBeTrue();
});

it('treats a re-post of the same natural key as a no-op (fake honours ADR-0002)', function () {
    $ledger = $this->ledger();
    $key = $this->postingKey('org_a', 'invoice', 'inv_1');

    $ledger->post($this->transfer('receivable', 'revenue', 10_000, id: 'tx_a', key: $key));
    $ledger->post($this->transfer('receivable', 'revenue', 10_000, id: 'tx_b', key: $key)); // reprocess

    // Balance is unchanged by the second post — the posted transaction was not mutated
    // and no second transaction was recorded.
    expect($ledger->balance('receivable', 'EUR')->minor())->toBe(10_000);
});

it('the fake still accumulates distinct natural keys', function () {
    $ledger = $this->ledger();

    $ledger->post($this->transfer('receivable', 'revenue', 10_000, id: 'a', key: $this->postingKey('org_a', 'invoice', 'inv_1')));
    $ledger->post($this->transfer('receivable', 'revenue', 5_000, id: 'b', key: $this->postingKey('org_a', 'invoice', 'inv_2')));

    expect($ledger->balance('receivable', 'EUR')->minor())->toBe(15_000);
});
