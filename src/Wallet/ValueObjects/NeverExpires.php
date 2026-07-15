<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Contracts\ExpiryPolicy;

/**
 * The lot never expires — `expiresAt` stays `null`. Not permitted for a pool that
 * `requiresExpiry`: {@see CreditGrant} rejects a null expiry there at construction.
 */
readonly class NeverExpires implements ExpiryPolicy
{
    public function expiresAt(int $grantedAtMs, int $periodEndMs): ?int
    {
        return null;
    }
}
