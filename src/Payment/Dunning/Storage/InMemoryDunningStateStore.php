<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Storage;

use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningState;

/**
 * In-memory {@see DunningStateStore} — the zero-config default and the base the test
 * fake extends. An account with no recorded progress reads as a fresh slate. Single
 * process, not durable; production binds a durable store on the same contract.
 */
class InMemoryDunningStateStore implements DunningStateStore
{
    /** @var array<string, DunningState> progress keyed by account */
    protected array $states = [];

    public function load(string $account): DunningState
    {
        return $this->states[$account] ?? DunningState::fresh();
    }

    public function save(string $account, DunningState $state): void
    {
        $this->states[$account] = $state;
    }
}
