<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

/**
 * The settle-once record for the paid effect, keyed on the payment/invoice reference —
 * the natural key the effect is idempotent on. It is the authoritative exactly-once
 * guard: two different gateway events that both mean "invoice X paid" still settle X
 * exactly once, and a re-delivery after the claim persisted is a no-op.
 *
 * In production the host binds a durable implementation where {@see SettledPaymentStore::settle()}
 * is a UNIQUE insert on the reference, committed in the SAME transaction as the invoice
 * update — so the claim and the effect are one atomic unit that survives a crash between
 * "handler returned" and "host persisted".
 */
interface SettledPaymentStore
{
    /**
     * Claim settlement for `$reference`. Returns true when this call is the one that
     * claimed it (the caller must apply the effect) and false when it was already
     * settled (a no-op).
     */
    public function settle(string $reference): bool;

    /** Whether `$reference` has already been settled. */
    public function isSettled(string $reference): bool;
}
