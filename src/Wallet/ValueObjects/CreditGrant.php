<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

use Cbox\Billing\Wallet\Enums\CreditType;

/**
 * One credit grant in a wallet. `remaining` is in the denomination's units
 * (money minor units, or a meter's units). `expiresAt` (ms epoch, null = never)
 * makes it use-it-or-lose-it; `priority` (lower burns first) places it in the
 * burn-down order when several grants can cover the same charge.
 */
readonly class CreditGrant
{
    public function __construct(
        public string $id,
        public string $org,
        public CreditType $type,
        public Denomination $denomination,
        public int $remaining,
        public ?int $expiresAt,
        public int $priority,
        public int $grantedAt,
    ) {}

    public function isActive(int $now): bool
    {
        return $this->remaining > 0 && ($this->expiresAt === null || $this->expiresAt > $now);
    }
}
