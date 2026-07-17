<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * A subscription's lifecycle state.
 *
 * The original engine had only `Active`/`Canceled`; the remaining cases were added
 * additively (existing persisted values keep resolving) to make the full lifecycle
 * first-class:
 *
 *  - `Trialing`    — created with a trial; serving the plan but not yet charging. At
 *                    trial end it converts to `Active` (first charge) or, per policy,
 *                    to a non-serving state.
 *  - `Active`      — the normal paying state.
 *  - `PastDue`     — a payment failed; still serving during dunning until recovered
 *                    (`Active`) or given up (`Canceled`).
 *  - `Paused`      — temporarily suspended; no billing while paused. Resuming shifts
 *                    the period forward by the paused span.
 *  - `NonRenewing` — a cancellation is scheduled for period end (`cancelAtPeriodEnd`).
 *                    It keeps serving until the current period renews, at which point
 *                    the renewal enacts the cancel and the subscription becomes
 *                    `Canceled`.
 *  - `Canceled`    — terminal; the org is on no plan.
 *
 * `Trialing`, `Active`, `PastDue` and `NonRenewing` are all *serving* states
 * ({@see Subscription::isActive()} is true for
 * them); `Paused` and `Canceled` are not.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case NonRenewing = 'non_renewing';
    case Canceled = 'canceled';

    /**
     * Whether the subscription is serving its plan in this state — the states a host
     * should grant entitlements for. Everything except {@see self::Paused} and
     * {@see self::Canceled}.
     */
    public function isServing(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue, self::NonRenewing => true,
            self::Paused, self::Canceled => false,
        };
    }

    /** Whether this is a terminal state that permits no further transition. */
    public function isTerminal(): bool
    {
        return $this === self::Canceled;
    }
}
