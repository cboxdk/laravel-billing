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
}
