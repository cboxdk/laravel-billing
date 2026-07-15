<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

/**
 * The node-local counter store holding the leased balance per (org, meter). The
 * default is backed by Laravel's cache using its own atomic `increment` /
 * `decrement` operations — no custom Lua, works on any cache driver (array in
 * tests, Redis/Memcached/database in production). {@see tryTake()} is a safe
 * atomic decrement-and-compensate, so it can only ever over-reject, never
 * over-grant. Single-node atomicity is all that is required — cross-node
 * consistency comes from pessimistic leasing, not from this store.
 *
 * ADR-0008 — these counters are a hot-path DERIVATION, not a loose cached scalar
 * balance. Every implementation MUST hold that invariant so a future optimization
 * cannot reintroduce the SET-vs-increment double-spend or the mid-period allowance
 * re-grant:
 *
 *   (a) Move a counter only by DELTAS ({@see addLease()}/{@see giveBack()}/
 *       {@see tryTake()} on the leased balance; {@see claimAllowance()}/
 *       {@see releaseAllowance()} on the allowance claim-counter). NEVER `SET` a
 *       counter to a computed sum — re-setting the leased balance to the authority's
 *       total would wipe in-flight, unreconciled spend and let it be spent twice.
 *   (b) Seed a COLD key from the durable authority — a missing counter reads/starts
 *       from the zero baseline (fail *open* to the authority, per ADR-0004), never an
 *       error and never a stale non-zero guess.
 *   (c) NEVER clear a counter mid-period. The allowance claim-counter is an
 *       authoritative claim register, not a cache: clearing it mid-period reseeds it
 *       from lagging storage and re-grants the included allowance to everyone.
 *       Counters expire only at the period boundary, via TTL.
 */
interface LocalStore
{
    /** Remaining leased units available locally for (org, meter). */
    public function remaining(string $org, string $meter): int;

    /** Add freshly leased units to the local balance. */
    public function addLease(string $org, string $meter, int $granted): void;

    /**
     * Atomically take `amount` units if at least that many remain. Returns true
     * on success (balance decremented), false if insufficient (unchanged).
     */
    public function tryTake(string $org, string $meter, int $amount): bool;

    /** Return `amount` units to the local balance (release / commit leftover). */
    public function giveBack(string $org, string $meter, int $amount): void;

    /**
     * Atomically claim a disjoint slice of the meter's ISOLATED allowance
     * consumption: increment the per-(org, meter) allowance-consumed counter by
     * `amount` and return the PRE-increment total — the start of the reserved
     * `[start, start+amount)` slice. Because the increment is atomic and returns the
     * position before it, concurrent claims receive disjoint slices, so each included
     * allowance unit is consumed exactly once and the exemption decision is computed
     * from the claimed position rather than a prior read.
     *
     * This is a separate counter from the leased balance above: allowance is the
     * bucket's own included pool, not the leased paid budget.
     *
     * ADR-0008: this is the period claim-counter — an authoritative claim register.
     * It advances by atomic DELTA only (increment on claim, {@see releaseAllowance()}
     * on rollback), seeds a cold key from the zero baseline, and is NEVER cleared
     * mid-period (that would reseed from lagging storage and re-grant the included
     * allowance). It expires only at the period boundary, via TTL.
     */
    public function claimAllowance(string $org, string $meter, int $amount): int;

    /**
     * Return `amount` units of previously-claimed allowance consumption (roll back a
     * claim when the downstream write fails, or return the unused tail on commit).
     */
    public function releaseAllowance(string $org, string $meter, int $amount): void;
}
