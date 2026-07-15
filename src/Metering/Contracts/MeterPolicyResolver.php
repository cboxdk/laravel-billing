<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * Resolves the per-bucket {@see MeterPolicy} for a `(org, meter)` — the entitlement
 * decision (enabled? + isolated allowance + weight + overage behaviour) the enforcer
 * evaluates before touching any counter.
 *
 * The implementation is supplied by the Entitlement module (entitlement decides what
 * each dimension is granted); the enforcer depends only on this contract.
 *
 * **Deny-by-default:** an unknown `(org, meter)` — no registered policy — resolves to
 * `null`, and the enforcer refuses it. A metered dimension is never silently trusted.
 */
interface MeterPolicyResolver
{
    /** The policy for `(org, meter)`, or `null` when nothing is entitled (refuse). */
    public function resolve(string $org, string $meter): ?MeterPolicy;
}
