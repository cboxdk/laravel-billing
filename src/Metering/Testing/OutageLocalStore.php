<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Testing;

use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Enums\OutcomeStatus;
use RuntimeException;

/**
 * A {@see LocalStore} that simulates an infrastructure outage: every operation throws,
 * as a downed cache/Redis or a transport timeout would. It lets a test prove the
 * ADR-0004 split — a dependency being unavailable yields an {@see OutcomeStatus::Indeterminate}
 * outcome resolved by the configurable infra policy (fail-open admit vs strict deny),
 * NOT a semantic `Denied`.
 *
 * `down` starts true; flip it with {@see recover()} to model a store that comes back.
 */
class OutageLocalStore implements LocalStore
{
    public function __construct(
        private bool $down = true,
    ) {}

    /** Bring the store back so subsequent operations behave like a real one would. */
    public function recover(): self
    {
        $this->down = false;

        return $this;
    }

    public function remaining(string $org, string $meter): int
    {
        $this->guard();

        return 0;
    }

    public function addLease(string $org, string $meter, int $granted): void
    {
        $this->guard();
    }

    public function tryTake(string $org, string $meter, int $amount): bool
    {
        $this->guard();

        return false;
    }

    public function giveBack(string $org, string $meter, int $amount): void
    {
        $this->guard();
    }

    public function claimAllowance(string $org, string $meter, int $amount): int
    {
        $this->guard();

        return 0;
    }

    public function releaseAllowance(string $org, string $meter, int $amount): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new RuntimeException('local store is unavailable (simulated outage)');
        }
    }
}
