<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Testing;

use Cbox\Billing\Metering\Contracts\EnforcementSignals;
use Cbox\Billing\Metering\ValueObjects\EnforcementOutcome;

/**
 * In-memory {@see EnforcementSignals} for tests: it records every indeterminate
 * outcome the enforcer reports so a test can assert the fail-open/fail-closed signal
 * actually fired (and inspect the fault + resolving policy), instead of only checking
 * the returned outcome.
 */
class RecordingEnforcementSignals implements EnforcementSignals
{
    /** @var list<EnforcementOutcome> */
    private array $indeterminate = [];

    public function indeterminate(EnforcementOutcome $outcome): void
    {
        $this->indeterminate[] = $outcome;
    }

    /** @return list<EnforcementOutcome> every indeterminate outcome signalled, in order. */
    public function indeterminateSignals(): array
    {
        return $this->indeterminate;
    }

    /** How many fail-open (admitted-despite-fault) signals were emitted. */
    public function failedOpenCount(): int
    {
        return count(array_filter($this->indeterminate, static fn (EnforcementOutcome $o): bool => $o->failedOpen()));
    }
}
