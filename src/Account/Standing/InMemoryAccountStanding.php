<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Standing;

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Account\Testing\FakeAccountStanding;

/**
 * In-memory {@see AccountStanding} — the zero-config default and the base the test
 * {@see FakeAccountStanding} extends. Single process, not durable; production binds a
 * durable store implementing the same contract.
 */
class InMemoryAccountStanding implements AccountStanding
{
    /** @var array<string, AccountStandingState> current standing keyed by account */
    protected array $standings = [];

    /** @var array<string, string> the reason behind the current standing, keyed by account */
    protected array $reasons = [];

    public function standingOf(string $account): AccountStandingState
    {
        return $this->standings[$account] ?? AccountStandingState::Good;
    }

    public function flag(string $account, AccountStandingState $state, string $reason): void
    {
        $this->standings[$account] = $state;
        $this->reasons[$account] = $reason;
    }

    /** The reason recorded for the account's current standing, or `null` if never flagged. */
    public function reasonFor(string $account): ?string
    {
        return $this->reasons[$account] ?? null;
    }
}
