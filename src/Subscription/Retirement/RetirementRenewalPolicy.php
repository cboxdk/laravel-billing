<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Retirement;

use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Cbox\Billing\Subscription\PlanChange\Exceptions\TransitionNotAllowed;
use Cbox\Billing\Subscription\Retirement\Exceptions\RetirementNotResolved;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use DateTimeImmutable;

/**
 * A thin renewal seam a host calls **in place of** {@see SubscriptionManager::renew()} when
 * a subscription may be on a retiring plan (ADR-0016). It resolves the subscription against
 * its plan's retirement via the pure {@see PlanRetirementResolver}, then enacts the verdict:
 *
 *  - `NotRetiring` / `RetiringChooseBy` → a plain renewal (the plan is still valid at this
 *    renewal, or the subscriber still has paid time and has not yet been forced) —
 *    behaviour identical to calling {@see SubscriptionManager::renew()} directly, so
 *    non-retiring subscriptions are unaffected.
 *  - `ResolvedToCancel` → a plain renewal, which enacts the already-scheduled period-end
 *    cancel — the subscriber keeps serving until this renewal, then ends.
 *  - `ResolvedToSuccessor` / `ResolvedToDefault` → migrate onto the successor, **validated
 *    through the {@see TransitionPolicy}** first; an illegal successor raises
 *    {@see TransitionNotAllowed} rather than a silent migration.
 *  - `UnresolvedRetirement` → refuse: raise {@see RetirementNotResolved}. A retired plan is
 *    never silently renewed or charged (deny-by-default).
 *
 * It owns no proration or state arithmetic of its own — it delegates every state change to
 * the {@see SubscriptionManager}, so the state machine stays the single source of truth.
 */
readonly class RetirementRenewalPolicy
{
    public function __construct(
        private SubscriptionManager $manager,
        private PlanRetirementResolver $resolver,
        private TransitionPolicy $policy,
    ) {}

    /**
     * Renew `$subscription` into `$nextPeriod` at `$now`, enacting any due retirement. The
     * successor's price is the one the subscriber scheduled (for a chosen successor) or the
     * catalog's effective price at `$now` (for the default successor).
     */
    public function renew(Subscription $subscription, Catalog $catalog, BillingPeriod $nextPeriod, DateTimeImmutable $now): Subscription
    {
        $resolution = $this->resolver->resolve($subscription, $catalog, $now);

        if ($resolution->isUnresolved()) {
            throw new RetirementNotResolved($subscription);
        }

        if ($resolution->migratesToSuccessor()) {
            return $this->migrate($subscription, $catalog, (string) $resolution->successorPlanId, $nextPeriod, $now);
        }

        // NotRetiring, RetiringChooseBy, and ResolvedToCancel all renew normally — the last
        // enacts the subscriber's already-scheduled period-end cancel.
        return $this->manager->renew($subscription, $nextPeriod);
    }

    /**
     * Migrate `$subscription` onto `$successorPlanId`, gating on the transition policy first
     * (ADR-0010): a disallowed target raises {@see TransitionNotAllowed} — never a silent
     * migration. The successor price is the scheduled one when the subscriber chose this
     * successor, else the catalog's effective price at `$now`.
     */
    private function migrate(Subscription $subscription, Catalog $catalog, string $successorPlanId, BillingPeriod $nextPeriod, DateTimeImmutable $now): Subscription
    {
        $from = $catalog->product($subscription->productId);
        $to = $catalog->product($successorPlanId);

        if ($from === null) {
            throw TransitionNotAllowed::because(
                new Product($subscription->productId, $subscription->productId),
                $to ?? new Product($successorPlanId, $successorPlanId),
                "Current plan [{$subscription->productId}] is not in the catalog.",
            );
        }

        if ($to === null) {
            throw TransitionNotAllowed::because(
                $from,
                new Product($successorPlanId, $successorPlanId),
                "Successor plan [{$successorPlanId}] is not in the catalog.",
            );
        }

        $decision = $this->policy->canTransition($from, $to);

        if (! $decision->isAllowed()) {
            throw TransitionNotAllowed::because($from, $to, (string) $decision->reason);
        }

        $priceId = $this->successorPriceId($subscription, $catalog, $successorPlanId, $now);

        if ($priceId === null) {
            throw TransitionNotAllowed::because(
                $from,
                $to,
                "Successor plan [{$successorPlanId}] has no price effective at the renewal.",
            );
        }

        return $this->manager->renewOntoPlan($subscription, $successorPlanId, $priceId, $nextPeriod);
    }

    /**
     * The price to migrate onto: the subscriber's scheduled price when they explicitly chose
     * this successor (a scheduled plan change onto it), otherwise the catalog's effective
     * price for the successor at `$now` (the default-successor path).
     */
    private function successorPriceId(Subscription $subscription, Catalog $catalog, string $successorPlanId, DateTimeImmutable $now): ?string
    {
        $change = $subscription->pendingChange;

        if ($change !== null && $change->newProductId === $successorPlanId) {
            return $change->newPriceId;
        }

        return $catalog->priceFor($successorPlanId, $now)?->id;
    }
}
