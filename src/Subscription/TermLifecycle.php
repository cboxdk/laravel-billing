<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Subscription\Enums\TermSubscriptionStatus;
use Cbox\Billing\Subscription\ValueObjects\RegistrarWindows;
use Cbox\Billing\Subscription\ValueObjects\TermSubscription;
use DateTimeImmutable;

/**
 * The registrar-style lifecycle of a fixed-term instance, as pure functions over a
 * {@see TermSubscription}, its product's {@see RegistrarWindows}, and an instant (ADR-0015).
 * Nothing here talks to a registry/EPP or provisions the resource — that is a connector's
 * job; this owns only the commercial lifecycle (term, phase, renew/redeem/transfer). Each
 * transition returns a new immutable instance.
 *
 * Phase boundaries (inclusive upper edge at each step):
 *   Active     while  now ≤ termEndsAt
 *   Grace      while  now ≤ termEndsAt + grace
 *   Redemption while  now ≤ termEndsAt + grace + redemption
 *   Expired    otherwise
 *
 * Pricing note: this service does not price anything. The caller selects the
 * {@see PriceKind} for the money movement — `Renewal` for {@see renew()}, `Redemption`
 * for {@see redeem()}, `Transfer` for {@see transferIn()} — and runs it through the
 * catalog + Quote pipeline. The `priceKindFor*` helpers name that choice.
 */
readonly class TermLifecycle
{
    /**
     * The effective status at `$now`. Settled terminal states (TransferredOut, Cancelled)
     * are preserved as-is. For an auto-renewing instance, passing the term end does NOT
     * drop it into Grace: it stays Active and {@see isAutoRenewalDue()} reports that a
     * renewal is due — the boundary between "the holder let it lapse" (manual → Grace) and
     * "the platform renews it automatically" (auto → stays Active, bill the renewal).
     */
    public function phaseAt(TermSubscription $subscription, RegistrarWindows $windows, DateTimeImmutable $now): TermSubscriptionStatus
    {
        if ($subscription->status->isTerminal()) {
            return $subscription->status;
        }

        if ($now <= $subscription->termEndsAt) {
            return TermSubscriptionStatus::Active;
        }

        if ($subscription->autoRenew) {
            return TermSubscriptionStatus::Active;
        }

        if ($now <= $windows->graceEndsAt($subscription->termEndsAt)) {
            return TermSubscriptionStatus::Grace;
        }

        if ($now <= $windows->redemptionEndsAt($subscription->termEndsAt)) {
            return TermSubscriptionStatus::Redemption;
        }

        return TermSubscriptionStatus::Expired;
    }

    /**
     * Whether an auto-renewing instance has reached its term end and a renewal is now due.
     * True only while it is still an Active, non-terminal, `autoRenew` instance past its
     * term end — the signal a billing run acts on to charge the {@see PriceKind::Renewal}
     * price and extend the term via {@see renew()}.
     */
    public function isAutoRenewalDue(TermSubscription $subscription, DateTimeImmutable $now): bool
    {
        return $subscription->autoRenew
            && ! $subscription->status->isTerminal()
            && $now > $subscription->termEndsAt;
    }

    /**
     * Renew for `$newTerm`, extending the term end from the later of `$at` and the current
     * term end — so renewing early stacks onto the remaining term (no time lost), while
     * renewing after lapse extends from the renewal instant. Returns an Active instance;
     * the caller charges the {@see PriceKind::Renewal} price.
     */
    public function renew(TermSubscription $subscription, Term $newTerm, DateTimeImmutable $at): TermSubscription
    {
        $base = $at > $subscription->termEndsAt ? $at : $subscription->termEndsAt;

        return new TermSubscription(
            $subscription->id,
            $subscription->orgId,
            $subscription->productId,
            $subscription->instanceRef,
            $newTerm,
            $subscription->registeredAt,
            $newTerm->addTo($base),
            $subscription->autoRenew,
            TermSubscriptionStatus::Active,
        );
    }

    /**
     * Redeem out of the redemption window back to Active for `$newTerm`. Redemption happens
     * after the term end, so the new term end is measured from `$at`. The caller charges the
     * {@see PriceKind::Redemption} price (the premium for recovery). Callers gate this on
     * {@see phaseAt()} returning Redemption.
     */
    public function redeem(TermSubscription $subscription, Term $newTerm, DateTimeImmutable $at): TermSubscription
    {
        return new TermSubscription(
            $subscription->id,
            $subscription->orgId,
            $subscription->productId,
            $subscription->instanceRef,
            $newTerm,
            $subscription->registeredAt,
            $newTerm->addTo($at),
            $subscription->autoRenew,
            TermSubscriptionStatus::Active,
        );
    }

    /** Transfer the instance out to another provider — terminal here. No money moves in this engine. */
    public function transferOut(TermSubscription $subscription, DateTimeImmutable $at): TermSubscription
    {
        return new TermSubscription(
            $subscription->id,
            $subscription->orgId,
            $subscription->productId,
            $subscription->instanceRef,
            $subscription->term,
            $subscription->registeredAt,
            $subscription->termEndsAt,
            $subscription->autoRenew,
            TermSubscriptionStatus::TransferredOut,
        );
    }

    /**
     * Cancel the instance — the holder gives it up. Terminal; the phase computation will
     * not resurrect it. Any post-expiry recovery windows do not apply to a cancellation.
     */
    public function cancel(TermSubscription $subscription): TermSubscription
    {
        return new TermSubscription(
            $subscription->id,
            $subscription->orgId,
            $subscription->productId,
            $subscription->instanceRef,
            $subscription->term,
            $subscription->registeredAt,
            $subscription->termEndsAt,
            $subscription->autoRenew,
            TermSubscriptionStatus::Cancelled,
        );
    }

    /**
     * Transfer an instance IN from another provider: a fresh Active instance whose term
     * runs from `$at`. The caller charges the {@see PriceKind::Transfer} price.
     */
    public function transferIn(
        string $id,
        string $orgId,
        string $productId,
        string $instanceRef,
        Term $term,
        DateTimeImmutable $at,
        bool $autoRenew = false,
    ): TermSubscription {
        return TermSubscription::register($id, $orgId, $productId, $instanceRef, $term, $at, $autoRenew);
    }
}
