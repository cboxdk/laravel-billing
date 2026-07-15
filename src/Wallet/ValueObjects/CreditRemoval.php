<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Enums\RemovalReason;

/**
 * One grant's unconsumed remainder removed from a wallet by an expiry sweep or a
 * forfeiture. Records the lot it came from (`grantId`), the account (`pool` key),
 * the `denomination` and the positive `amount` that was removed, plus why
 * (`reason`) and when (`at`). Consumers post this to the ledger or count it for
 * regulatory reporting; the wallet only ever removes a positive remainder, so
 * `amount` is always `> 0` and a `mayGoNegative` sink's debt is never disturbed.
 */
readonly class CreditRemoval
{
    public function __construct(
        public string $grantId,
        public string $org,
        public string $pool,
        public Denomination $denomination,
        public int $amount,
        public RemovalReason $reason,
        public int $at,
    ) {}
}
