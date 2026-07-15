<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Enums;

/**
 * What a bucket does once its isolated allowance is exhausted.
 *
 *  - `Block` — refuse at the allowance boundary; usage never exceeds the included
 *              allowance (a hard limit on the dimension).
 *  - `Bill`  — permit usage beyond the allowance, charged at the bucket's weighted
 *              cost and drawn from the leased paid budget (still a hard spend cap).
 *
 * A `null`/unrecognized behaviour is NOT modelled here — it is the absence of a
 * resolved policy, which the enforcer fails **closed** on (deny-by-default, per
 * ADR-0004): a semantic unknown is refused, never silently billed or admitted.
 */
enum OverageBehaviour: string
{
    case Block = 'block';
    case Bill = 'bill';
}
