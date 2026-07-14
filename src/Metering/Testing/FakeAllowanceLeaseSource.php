<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Testing;

use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Metering\ValueObjects\AllowanceLease;

/**
 * In-memory pessimistic lease source for tests — stands in for billing. Give an
 * org a total allowance per meter; each lease reserves from it and can never
 * over-grant (the hard-limit invariant), and returned units become available
 * again. Also exposes what's been leased/returned so tests can assert drift.
 */
class FakeAllowanceLeaseSource implements AllowanceLeaseSource
{
    /** @var array<string, int> total allowance per org:meter */
    private array $allowance = [];

    /** @var array<string, int> currently leased-out per org:meter */
    private array $leased = [];

    public function grant(string $org, string $meter, int $allowance): self
    {
        $this->allowance[$this->key($org, $meter)] = $allowance;

        return $this;
    }

    public function lease(string $org, string $meter, int $want): AllowanceLease
    {
        $key = $this->key($org, $meter);
        $available = ($this->allowance[$key] ?? 0) - ($this->leased[$key] ?? 0);
        $granted = max(0, min($want, $available));

        $this->leased[$key] = ($this->leased[$key] ?? 0) + $granted;

        return new AllowanceLease($org, $meter, $granted);
    }

    public function giveBack(string $org, string $meter, int $unused): void
    {
        if ($unused <= 0) {
            return;
        }

        $key = $this->key($org, $meter);
        $this->leased[$key] = max(0, ($this->leased[$key] ?? 0) - $unused);
    }

    /** Units currently leased out (held by nodes) for (org, meter). */
    public function leasedOut(string $org, string $meter): int
    {
        return $this->leased[$this->key($org, $meter)] ?? 0;
    }

    private function key(string $org, string $meter): string
    {
        return $org.':'.$meter;
    }
}
