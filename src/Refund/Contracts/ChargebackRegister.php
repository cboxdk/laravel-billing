<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Contracts;

use Cbox\Billing\Refund\ValueObjects\Chargeback;

/**
 * The register of recorded chargebacks, keyed on the network's dispute reference. It
 * is what makes chargeback handling idempotent: {@see find()} returns an already-
 * recorded dispute so a re-delivered webhook is a no-op.
 */
interface ChargebackRegister
{
    public function find(string $disputeReference): ?Chargeback;

    public function record(Chargeback $chargeback): void;
}
