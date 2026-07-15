<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Storage;

use Cbox\Billing\Refund\Contracts\ChargebackRegister;
use Cbox\Billing\Refund\ValueObjects\Chargeback;

/**
 * In-memory {@see ChargebackRegister} — the zero-config default and the dogfood store
 * the package's own tests use. Single process, not durable; production binds a durable
 * register on the ledger's connection so the dispute record and its reversal commit
 * together.
 */
class InMemoryChargebackRegister implements ChargebackRegister
{
    /** @var array<string, Chargeback> keyed by dispute reference */
    private array $byReference = [];

    public function find(string $disputeReference): ?Chargeback
    {
        return $this->byReference[$disputeReference] ?? null;
    }

    public function record(Chargeback $chargeback): void
    {
        $this->byReference[$chargeback->disputeReference] = $chargeback;
    }
}
