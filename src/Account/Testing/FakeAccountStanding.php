<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Testing;

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Account\Standing\InMemoryAccountStanding;

/**
 * The dogfood {@see AccountStanding} for tests: the
 * in-memory store plus a recorded history of every flag, so a test can prove exactly
 * how an account's standing moved (and that a re-delivered event did not move it twice).
 */
class FakeAccountStanding extends InMemoryAccountStanding
{
    /** @var list<array{account: string, state: AccountStandingState, reason: string}> */
    public array $transitions = [];

    public function flag(string $account, AccountStandingState $state, string $reason): void
    {
        parent::flag($account, $state, $reason);

        $this->transitions[] = ['account' => $account, 'state' => $state, 'reason' => $reason];
    }
}
