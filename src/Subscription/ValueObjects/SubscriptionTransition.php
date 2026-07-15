<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

/**
 * A movement between subscription states for one organization: the `before` state
 * and the `after` state, either of which may be absent. Forfeiture keys on the shape
 * of this transition, never on a destination plan id — the distinction ADR-0006
 * makes to stop "cancel to no plan" slipping past a rule written for "downgrade".
 *
 *  - `started(after)`      — from nothing onto a subscription (never forfeits).
 *  - `switched(before, after)` — from one subscription onto another.
 *  - `canceled(before)`    — off a subscription onto no plan at all (cancel-to-null).
 *
 * A transition **left without landing** when the org was on a subscription and does
 * not end on an active one — whether the destination is null (cancel) or an inactive
 * subscription (a downgrade that resolves to pay-as-you-go with no plan). That single
 * predicate covers both, so forfeiture cannot miss a cancellation.
 */
readonly class SubscriptionTransition
{
    public function __construct(
        public ?Subscription $before,
        public ?Subscription $after,
    ) {}

    public static function between(?Subscription $before, ?Subscription $after): self
    {
        return new self($before, $after);
    }

    public static function started(Subscription $after): self
    {
        return new self(null, $after);
    }

    public static function switched(Subscription $before, Subscription $after): self
    {
        return new self($before, $after);
    }

    public static function canceled(Subscription $before): self
    {
        return new self($before, null);
    }

    /** The org left a subscription and did not land on an active one. */
    public function leftWithoutLanding(): bool
    {
        return $this->before !== null && ($this->after === null || ! $this->after->isActive());
    }

    /** The organization that moved, taken from whichever side of the transition exists. */
    public function organizationId(): ?string
    {
        if ($this->before !== null) {
            return $this->before->organizationId;
        }

        return $this->after?->organizationId;
    }
}
