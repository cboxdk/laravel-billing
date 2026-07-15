<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Testing;

use Cbox\Billing\Payment\Dunning\Contracts\DelinquentAllowList;
use Cbox\Billing\Payment\Dunning\Storage\InMemoryDelinquentAllowList;

/**
 * The dogfood {@see DelinquentAllowList} for
 * tests: the in-memory allow-list plus a record of every account queried, so a test can
 * prove dunning consulted the bypass flag before acting.
 */
class FakeDelinquentAllowList extends InMemoryDelinquentAllowList
{
    /** @var list<string> every account the runner asked about, in order */
    public array $queried = [];

    public function allows(string $account): bool
    {
        $this->queried[] = $account;

        return parent::allows($account);
    }
}
