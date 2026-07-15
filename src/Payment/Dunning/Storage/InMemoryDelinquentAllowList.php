<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Storage;

use Cbox\Billing\Payment\Dunning\Contracts\DelinquentAllowList;

/**
 * In-memory {@see DelinquentAllowList} — the zero-config default and the base the test
 * fake extends. Deny-by-default: only accounts explicitly {@see allow()}ed are exempt.
 * Single process, not durable; production binds a durable store on the same contract.
 */
class InMemoryDelinquentAllowList implements DelinquentAllowList
{
    /** @var array<string, true> allow-listed accounts */
    protected array $allowed = [];

    public function allows(string $account): bool
    {
        return isset($this->allowed[$account]);
    }

    /** Add `$account` to the bypass allow-list — it will not be dunned. */
    public function allow(string $account): void
    {
        $this->allowed[$account] = true;
    }

    /** Remove `$account` from the bypass allow-list — normal dunning resumes. */
    public function disallow(string $account): void
    {
        unset($this->allowed[$account]);
    }
}
