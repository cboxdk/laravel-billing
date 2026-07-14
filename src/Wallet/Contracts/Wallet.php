<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Contracts;

use Cbox\Billing\Wallet\CreditConsumer;
use Cbox\Billing\Wallet\ValueObjects\ConsumptionPlan;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

/**
 * An organization's wallet of credit grants. `consume()` runs the burn-down
 * ({@see CreditConsumer}) and applies it — decrementing the
 * drawn grants — returning the plan (which may report a shortfall for the overage
 * policy to handle). A durable implementation additionally posts each monetary
 * drawdown to the ledger; balances are always derived, never stored loose.
 */
interface Wallet
{
    public function grant(CreditGrant $grant): void;

    /**
     * Consume `amount` of `denomination` for `org`, applying the burn-down.
     */
    public function consume(string $org, Denomination $denomination, int $amount, int $now): ConsumptionPlan;

    /** Total active (non-expired) remaining for `org` in `denomination`. */
    public function balance(string $org, Denomination $denomination, int $now): int;
}
