<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * A credit grant violates its pool's behaviour matrix — e.g. a grant into a pool
 * that `requiresExpiry` was created without an `expiresAt`. Raised at construction
 * so an invalid grant can never enter a wallet.
 */
class InvalidGrant extends InvalidArgumentException
{
    public static function missingExpiry(string $pool): self
    {
        return new self("A grant into pool [{$pool}] must carry an expiry, but none was given.");
    }
}
