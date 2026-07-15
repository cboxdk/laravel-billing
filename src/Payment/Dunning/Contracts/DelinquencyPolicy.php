<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Contracts;

use Cbox\Billing\Payment\Dunning\ValueObjects\DunningConfig;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningOutcome;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningSnapshot;
use DateTimeImmutable;

/**
 * The delinquency decision. A pure function over (account snapshot, config, now) that
 * yields the single action to take — send a reminder, suspend, restore, or nothing.
 * It performs no I/O and mutates nothing: the runner assembles the snapshot, calls
 * {@see decide()}, then applies the outcome. Contracts-first so a host can swap the
 * decision rules without touching the runner or the stores.
 */
interface DelinquencyPolicy
{
    public function decide(DunningSnapshot $snapshot, DunningConfig $config, DateTimeImmutable $now): DunningOutcome;
}
