<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Stores;

use Cbox\Billing\Metering\Contracts\LocalStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * {@see LocalStore} backed by Laravel's cache — the default node-local counter.
 * It uses only the cache's own atomic `increment` / `decrement` operations (no
 * custom Lua), so it works unchanged on the array driver in tests and on
 * Redis/Memcached/database in production.
 *
 * {@see tryTake()} is a **decrement-and-compensate**: decrement atomically, and if
 * the balance went negative, atomically add it back and reject. Both steps are
 * atomic, so under concurrency this can only ever over-reject, never over-grant —
 * exactly the safe direction for a hard limit.
 *
 * ADR-0008 (delta-not-SET): every mutator here is an atomic DELTA — `increment` on a
 * grant/refill/give-back/claim, `decrement` on a take/release. Nothing ever `SET`s a
 * counter to a computed sum (which would wipe in-flight spend and let it be spent
 * twice), and nothing ever clears a counter mid-period (which would reseed a lagging
 * allowance and re-grant it). A cold key seeds from the zero baseline — `increment`
 * itself starts a missing key at 0, and the reads here fall open to 0 — so the
 * derived balance fails open to the authority rather than erroring. Counters are
 * expired only at the period boundary, via the cache TTL. If you extend this class,
 * keep it to `get`/`increment`/`decrement`; introducing `put`/`forever`/`forget`/
 * `flush` reintroduces the double-spend the derivation is designed to prevent.
 */
class CacheLocalStore implements LocalStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'cbox-billing:lease:',
    ) {}

    public function remaining(string $org, string $meter): int
    {
        // `is_numeric` also covers the raw integer string a Redis INCRBY leaves,
        // which `get()` would otherwise not round-trip.
        $value = $this->cache->get($this->key($org, $meter), 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function addLease(string $org, string $meter, int $granted): void
    {
        if ($granted <= 0) {
            return;
        }

        $this->cache->increment($this->key($org, $meter), $granted);
    }

    public function tryTake(string $org, string $meter, int $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $key = $this->key($org, $meter);
        $after = $this->cache->decrement($key, $amount);

        // A store that can't decrement (missing/non-numeric) yields no take.
        if (! is_int($after)) {
            return false;
        }

        if ($after < 0) {
            $this->cache->increment($key, $amount);

            return false;
        }

        return true;
    }

    public function giveBack(string $org, string $meter, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->cache->increment($this->key($org, $meter), $amount);
    }

    public function claimAllowance(string $org, string $meter, int $amount): int
    {
        if ($amount <= 0) {
            return $this->consumedAllowance($org, $meter);
        }

        // The cache's atomic increment returns the POST-increment total; the slice
        // start is that minus what we just added — computed from the claimed
        // position, never a prior read. `increment` seeds a missing key from 0.
        $after = $this->cache->increment($this->allowanceKey($org, $meter), $amount);

        if (! is_int($after)) {
            // A store that cannot increment yields a slice starting past any sane
            // allowance, so the claim is all overage — fail towards charging, never
            // towards a free exemption.
            return PHP_INT_MAX;
        }

        return $after - $amount;
    }

    public function releaseAllowance(string $org, string $meter, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->cache->decrement($this->allowanceKey($org, $meter), $amount);
    }

    private function consumedAllowance(string $org, string $meter): int
    {
        $value = $this->cache->get($this->allowanceKey($org, $meter), 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function key(string $org, string $meter): string
    {
        return $this->prefix.$org.':'.$meter;
    }

    private function allowanceKey(string $org, string $meter): string
    {
        return $this->prefix.'allowance:'.$org.':'.$meter;
    }
}
