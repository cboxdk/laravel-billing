<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Contracts;

use Cbox\Billing\Metering\ValueObjects\EnforcementOutcome;

/**
 * The operator-facing signal channel for enforcement (ADR-0004). When a decision
 * cannot be reached because a dependency is down, the enforcer resolves it via the
 * configured infra failure policy AND emits a signal here, so a fail-open admission
 * (or a strict fail-closed refusal) is never silent — operators can alert on it and
 * reconciliation can be watched.
 *
 * The default binding logs; hosts may swap in a metrics/alerting implementation. Only
 * `Indeterminate` outcomes are signalled — reached `Allowed`/`Denied` decisions are
 * the normal hot path and are not noise-worthy.
 */
interface EnforcementSignals
{
    /** An infrastructure fault made a decision indeterminate; report how it resolved. */
    public function indeterminate(EnforcementOutcome $outcome): void;
}
