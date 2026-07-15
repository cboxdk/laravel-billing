<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Signals;

use Cbox\Billing\Metering\Contracts\EnforcementSignals;
use Cbox\Billing\Metering\ValueObjects\EnforcementOutcome;
use Psr\Log\LoggerInterface;

/**
 * The default {@see EnforcementSignals}: emit a PSR-3 log line whenever an
 * infrastructure fault made a decision indeterminate. A fail-OPEN admission logs at
 * `warning` (traffic was let through un-metered and now depends on reconciliation); a
 * strict fail-CLOSED refusal logs at `error` (an outage is actively refusing traffic).
 * Either way operators get a durable, alertable record of the infra path firing.
 */
class LoggingEnforcementSignals implements EnforcementSignals
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function indeterminate(EnforcementOutcome $outcome): void
    {
        $context = [
            'org' => $outcome->org,
            'meters' => $outcome->meters,
            'fault' => $outcome->fault?->reason,
            'policy' => $outcome->resolvedBy?->value,
        ];

        if ($outcome->failedOpen()) {
            $this->logger->warning(
                'Metering enforcement failed open on an infrastructure fault: admitting traffic and deferring to reconciliation.',
                $context,
            );

            return;
        }

        $this->logger->error(
            'Metering enforcement failed closed on an infrastructure fault: refusing traffic under the strict infra policy.',
            $context,
        );
    }
}
