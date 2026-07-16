<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use Cbox\Billing\Subscription\ValueObjects\TermSubscription;

/**
 * The billing/fulfilment shape of a sellable product — a first-class catalog attribute
 * that selects which lifecycle and pricing semantics apply (ADR-0015):
 *
 *  - `Metered`   — a usage-metered plan: entitlement + real-time metering, billed on
 *                  consumption against allowances/credits.
 *  - `Recurring` — a rolling subscription: cycle-anchored, prorated, renews indefinitely
 *                  until cancelled (the {@see Subscription} shape).
 *  - `FixedTerm` — a registrar-style committed term: bought for a chosen {@see Term}
 *                  (1/2/5 yr) with distinct register/renewal/transfer/redemption pricing and a
 *                  post-expiry grace→redemption→expiry lifecycle (the
 *                  {@see TermSubscription} shape).
 *  - `OneTime`   — a single non-recurring charge (a setup fee, a one-off purchase).
 *
 * The shape decides which subscription/entitlement machinery drives an instance; it does
 * not change how a {@see Price} version is resolved
 * (grandfathering by effective date applies to every shape).
 */
enum ProductShape: string
{
    case Metered = 'metered';
    case Recurring = 'recurring';
    case FixedTerm = 'fixed_term';
    case OneTime = 'one_time';
}
