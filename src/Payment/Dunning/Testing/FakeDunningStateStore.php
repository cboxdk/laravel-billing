<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Testing;

use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Dunning\Storage\InMemoryDunningStateStore;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningState;

/**
 * The dogfood {@see DunningStateStore} for
 * tests: the in-memory store plus a recorded history of every saved state, so a test
 * can prove exactly how an account's notice progress advanced across dunning runs.
 */
class FakeDunningStateStore extends InMemoryDunningStateStore
{
    /** @var list<array{account: string, state: DunningState}> */
    public array $saves = [];

    public function save(string $account, DunningState $state): void
    {
        parent::save($account, $state);

        $this->saves[] = ['account' => $account, 'state' => $state];
    }
}
