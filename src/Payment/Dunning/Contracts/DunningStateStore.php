<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Contracts;

use Cbox\Billing\Payment\Dunning\ValueObjects\DunningState;

/**
 * Where the progress of each account's notice sequence lives — how many reminders have
 * gone out and when the last did. The runner reads it to build the snapshot and writes
 * it back when a reminder is sent or the account is restored. An account with no
 * recorded progress reads as a {@see DunningState::fresh()} slate.
 */
interface DunningStateStore
{
    /** The account's current dunning progress; a fresh slate when none is recorded. */
    public function load(string $account): DunningState;

    /** Persist the account's dunning progress. */
    public function save(string $account, DunningState $state): void;
}
