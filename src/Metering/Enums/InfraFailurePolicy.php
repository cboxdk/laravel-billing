<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Enums;

/**
 * How an {@see OutcomeStatus::Indeterminate} result — a decision that could not be
 * reached because a dependency was down — is resolved (ADR-0004). This is a
 * per-deployment knob, NOT a per-request decision:
 *
 *  - `Allow` — fail **open** (the default): admit the request so a cache/DB blip does
 *              not throttle legitimate paid traffic. The durable ledger stays the
 *              eventual authority and reconciliation (ADR-0003/0008) recovers the
 *              truth. A signal is emitted so operators see the fail-open.
 *  - `Deny`  — fail **closed** for strict tenants that would rather refuse than admit
 *              un-metered usage during an outage.
 *
 * Note this governs INFRASTRUCTURE faults only. Semantic unknowns always fail closed
 * regardless of this policy — they are `Denied`, never `Indeterminate`.
 */
enum InfraFailurePolicy: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    /** The deploy-safe default: preserve availability, reconcile after the fact. */
    public static function default(): self
    {
        return self::Allow;
    }

    /** Resolve from a config string, falling back to the fail-open default. */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::default())
            : self::default();
    }
}
