<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Contracts;

/**
 * The per-account bypass allow-list — the `delinquent_allowed` flag. An account on the
 * list is exempt from dunning entirely: no reminders, no escalation, no suspension,
 * regardless of how overdue it is. This is the operator's escape hatch for accounts
 * that are settled out of band or under a manual arrangement.
 *
 * Deny-by-default: an account is NOT bypassed unless it has been explicitly allowed, so
 * the allow-list can only ever weaken dunning for a named account, never enable it.
 */
interface DelinquentAllowList
{
    /** Whether `$account` is exempt from dunning. */
    public function allows(string $account): bool;
}
