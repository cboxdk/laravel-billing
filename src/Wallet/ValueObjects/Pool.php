<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Support\Pools;
use InvalidArgumentException;

/**
 * A credit account within a wallet, identified by `key`, with an explicit
 * behaviour matrix. The behaviour lives on the account, not on each grant, so
 * every grant deposited into the same pool obeys the same rules:
 *
 *  - `spendable`        — may fund general usage (appears in a consumption order).
 *  - `mayGoNegative`    — accrues uncovered demand as debt: the PAYG sink.
 *  - `forfeitsOnCancel` — zeroed when the org leaves the granting subscription.
 *  - `requiresExpiry`   — a grant into this pool must carry an `expiresAt`.
 *  - `reportable`       — counted for regulatory reporting even when not spendable.
 *
 * Hosts may construct their own pools; {@see Pools}
 * ships a sensible default catalog.
 */
readonly class Pool
{
    public function __construct(
        public string $key,
        public bool $spendable,
        public bool $mayGoNegative,
        public bool $forfeitsOnCancel,
        public bool $requiresExpiry,
        public bool $reportable,
    ) {
        // Deny the unmodelled: a debt-absorbing account must be spendable, otherwise
        // "negative balance" has no meaning — nothing is ever drawn against it.
        if ($mayGoNegative && ! $spendable) {
            throw new InvalidArgumentException(
                "Pool [{$key}] may go negative but is not spendable; a non-spendable pool cannot absorb overage.",
            );
        }
    }

    /** Two pools are the same account when their keys match. */
    public function sameAs(self $other): bool
    {
        return $this->key === $other->key;
    }

    /** The pool that absorbs uncovered demand as debt when it is last in a consumption order. */
    public function absorbsOverage(): bool
    {
        return $this->mayGoNegative;
    }
}
