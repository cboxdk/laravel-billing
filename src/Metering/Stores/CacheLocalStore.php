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

    private function key(string $org, string $meter): string
    {
        return $this->prefix.$org.':'.$meter;
    }
}
