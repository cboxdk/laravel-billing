<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

/**
 * How a mid-cycle upgrade grants the incoming plan's credit allotment (ADR-0012).
 *
 * - `FullReset` (default): forfeit the unspent outgoing allotment and grant the
 *   incoming plan's **full** cycle allotment for the remainder of the cycle. The money
 *   proration (ADR-0007) already charged the prorated capacity, so the customer gets
 *   the new limit immediately. Correct for allotments that are a per-cycle *quota*.
 * - `Prorated`: grant only `remaining_days / total_days × new_allotment`
 *   (remainder-safe). For plans where a credit unit maps directly to money, a full
 *   reset on a frequent mid-cycle upgrade would over-grant — the customer would get a
 *   whole cycle's credits having paid only for the remaining days.
 */
enum CreditGrantMode: string
{
    case FullReset = 'full_reset';
    case Prorated = 'prorated';
}
