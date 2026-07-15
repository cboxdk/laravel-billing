<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Signals;

use Cbox\Billing\Metering\Contracts\EnforcementSignals;
use Cbox\Billing\Metering\ValueObjects\EnforcementOutcome;

/**
 * A no-op {@see EnforcementSignals}. The safe default when an enforcer is constructed
 * without a signal channel — fail-open still WORKS, it is simply not reported. Hosts
 * that care about observability bind {@see LoggingEnforcementSignals} (or their own).
 */
class NullEnforcementSignals implements EnforcementSignals
{
    public function indeterminate(EnforcementOutcome $outcome): void
    {
        // Intentionally does nothing.
    }
}
