<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Contracts;

use Cbox\Billing\Account\Enums\AccountStandingState;

/**
 * A billing account's standing — the trust state access is gated on. It is moved by
 * billing events (most notably a chargeback flags the account {@see
 * AccountStandingState::Disputed}) and read at the access boundary.
 *
 * Keyed on the billing account (the `account` org identifier used across the
 * package). An account with no recorded standing reads as {@see
 * AccountStandingState::Good} — standing records trouble, it is not an authorization
 * allow-list, so an untouched account keeps normal access until something flags it.
 */
interface AccountStanding
{
    /** The account's current standing; {@see AccountStandingState::Good} when never flagged. */
    public function standingOf(string $account): AccountStandingState;

    /**
     * Move `$account` to `$state`, recording `$reason` (e.g. a dispute reference) for
     * audit. Overwrites the current standing; the caller decides the transition.
     */
    public function flag(string $account, AccountStandingState $state, string $reason): void;
}
