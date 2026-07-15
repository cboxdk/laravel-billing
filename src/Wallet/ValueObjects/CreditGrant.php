<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Exceptions\InvalidGrant;

/**
 * One credit grant in a wallet. It lives in a {@see Pool} whose behaviour matrix
 * governs it (spendable, may-go-negative, forfeit-on-cancel, must-expire, reportable).
 * `remaining` is in the denomination's units (money minor units, or a meter's units)
 * and may be negative only when the pool `mayGoNegative`. `expiresAt` (ms epoch,
 * null = never) makes it use-it-or-lose-it; `priority` (lower burns first) orders it
 * within its pool when several grants can cover the same charge. `kind` and `cadence`
 * record how a plan issued it.
 *
 * A grant into a pool that `requiresExpiry` must carry an `expiresAt`: construction
 * rejects it otherwise, so an invalid grant can never reach a wallet.
 */
readonly class CreditGrant
{
    public function __construct(
        public string $id,
        public string $org,
        public Pool $pool,
        public Denomination $denomination,
        public int $remaining,
        public ?int $expiresAt,
        public int $priority = 0,
        public int $grantedAt = 0,
        public GrantKind $kind = GrantKind::Base,
        public GrantCadence $cadence = GrantCadence::Once,
    ) {
        if ($pool->requiresExpiry && $expiresAt === null) {
            throw InvalidGrant::missingExpiry($pool->key);
        }
    }

    /** Live (not yet expired) at `now`; balance-bearing even when `remaining` is 0 or negative. */
    public function isActive(int $now): bool
    {
        return $this->expiresAt === null || $this->expiresAt > $now;
    }

    /** Has this lot expired by `now`? (A never-expiring lot never has.) */
    public function hasExpired(int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /** A copy of this lot with a new `remaining`; every other attribute is preserved. */
    public function withRemaining(int $remaining): self
    {
        return new self(
            id: $this->id,
            org: $this->org,
            pool: $this->pool,
            denomination: $this->denomination,
            remaining: $remaining,
            expiresAt: $this->expiresAt,
            priority: $this->priority,
            grantedAt: $this->grantedAt,
            kind: $this->kind,
            cadence: $this->cadence,
        );
    }

    /** Has spendable balance right now: live and holding a positive remainder. */
    public function isSpendable(int $now): bool
    {
        return $this->pool->spendable && $this->remaining > 0 && $this->isActive($now);
    }
}
