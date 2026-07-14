<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\ValueObjects\ConsumptionPlan;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;

/**
 * In-memory {@see Wallet} — proves the grant lifecycle: hold grants, run the
 * burn-down, and apply it by decrementing the drawn grants. A durable wallet
 * (Eloquent) additionally posts monetary drawdowns to the ledger; the contract
 * and burn-down are identical.
 */
class InMemoryWallet implements Wallet
{
    /** @var array<string, CreditGrant> keyed by grant id */
    private array $grants = [];

    public function __construct(private readonly CreditConsumer $consumer = new CreditConsumer) {}

    public function grant(CreditGrant $grant): void
    {
        $this->grants[$grant->id] = $grant;
    }

    public function consume(string $org, Denomination $denomination, int $amount, int $now): ConsumptionPlan
    {
        $plan = $this->consumer->plan($org, $denomination, $amount, array_values($this->grants), $now);

        foreach ($plan->draws as $draw) {
            $grant = $this->grants[$draw->grantId];
            $this->grants[$draw->grantId] = new CreditGrant(
                id: $grant->id,
                org: $grant->org,
                type: $grant->type,
                denomination: $grant->denomination,
                remaining: $grant->remaining - $draw->amount,
                expiresAt: $grant->expiresAt,
                priority: $grant->priority,
                grantedAt: $grant->grantedAt,
            );
        }

        return $plan;
    }

    public function balance(string $org, Denomination $denomination, int $now): int
    {
        $total = 0;

        foreach ($this->grants as $grant) {
            if ($grant->org === $org && $grant->denomination->matches($denomination) && $grant->isActive($now)) {
                $total += $grant->remaining;
            }
        }

        return $total;
    }
}
