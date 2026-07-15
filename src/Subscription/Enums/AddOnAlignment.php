<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

/**
 * How an add-on's billing cycle relates to its base subscription (ADR-0012).
 *
 * - `Aligned` (default): the add-on shares the base subscription's period and anchor.
 *   A mid-cycle add prorates to the **base** period, and its credit allotment follows
 *   the base allotment rules (full-reset vs prorated). The common case — one invoice,
 *   one renewal date.
 * - `Independent`: the add-on runs on its **own** {@see BillingCycle} — its own anchor,
 *   interval, and period math — for add-ons that bill on a different cadence than the
 *   base (e.g. an annual add-on on a monthly base).
 */
enum AddOnAlignment: string
{
    case Aligned = 'aligned';
    case Independent = 'independent';
}
