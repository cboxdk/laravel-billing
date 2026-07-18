<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Retirement;

use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Catalog\ValueObjects\PlanRetirement;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use DateTimeImmutable;

/**
 * Pure resolution of a subscription against its plan's retirement (ADR-0016): given the
 * subscription, the catalog, and an instant, it decides what that subscription's upcoming
 * renewal does about the sunset — with no side effects.
 *
 * The rules:
 *  - The plan carries no {@see PlanRetirement}, or `$now` is **before** its `retiresAt`
 *    cutoff → `NotRetiring`: the plan is still valid at this renewal, so nothing is forced
 *    and the subscriber keeps their paid time.
 *  - `$now` is **on/after** the cutoff (the plan is retired) → the subscriber must resolve:
 *      - a scheduled **successor** plan → `ResolvedToSuccessor`;
 *      - a scheduled **cancel** → `ResolvedToCancel`;
 *      - no choice, but the renewal is not yet due (paid time remains) →
 *        `RetiringChooseBy(renewalDueDate, default)`;
 *      - no choice, renewal due, a default successor configured → `ResolvedToDefault`;
 *      - no choice, renewal due, no default → `UnresolvedRetirement` (deny-by-default).
 *
 * The **renewal-due date** — the deadline by which the subscriber must choose — is the
 * subscription's next period boundary on/after the cutoff, computed from its billing
 * period (or carried {@see BillingCycle} when it
 * has one). No one loses paid time: a subscriber mid-period at the cutoff resolves at the
 * boundary, not immediately.
 */
readonly class PlanRetirementResolver
{
    /** Bound on the walk that projects the renewal-due date, to preclude any accidental loop. */
    private const int MAX_PERIODS_AHEAD = 1200;

    public function resolve(Subscription $subscription, Catalog $catalog, DateTimeImmutable $now): RetirementResolution
    {
        $product = $catalog->product($subscription->productId);
        $retirement = $product?->retirement;

        // No sunset, or the cutoff has not been reached: renew normally.
        if ($retirement === null || ! $retirement->isRetiredAt($now)) {
            return RetirementResolution::notRetiring();
        }

        // The plan is retired. An explicit choice resolves it regardless of paid time left.
        if ($this->hasScheduledSuccessor($subscription)) {
            $successorId = $subscription->pendingChange?->newProductId;

            // $successorId is non-null here by the guard, but assert for the type-checker.
            return RetirementResolution::resolvedToSuccessor((string) $successorId);
        }

        if ($subscription->cancelAtPeriodEnd) {
            return RetirementResolution::resolvedToCancel();
        }

        // No choice. If the renewal is not yet due, the subscriber still has paid time and
        // must choose by the renewal-due date; otherwise the retirement is enacted now.
        $renewalDueDate = $this->renewalDueDate($subscription, $retirement->retiresAt);

        if ($now < $renewalDueDate) {
            return RetirementResolution::retiringChooseBy($renewalDueDate, $retirement->defaultSuccessorPlanId);
        }

        if ($retirement->hasDefaultSuccessor()) {
            return RetirementResolution::resolvedToDefault((string) $retirement->defaultSuccessorPlanId);
        }

        return RetirementResolution::unresolved();
    }

    /**
     * Whether the subscriber has elected a successor: a scheduled plan change onto a
     * product other than the one they are on. A bare price change (no product change) does
     * not count — it does not resolve the retirement.
     */
    private function hasScheduledSuccessor(Subscription $subscription): bool
    {
        $change = $subscription->pendingChange;

        return $change !== null
            && $change->newProductId !== null
            && $change->newProductId !== $subscription->productId;
    }

    /**
     * The subscription's next renewal boundary on/after `$retiresAt` — the deadline by
     * which the subscriber must resolve. Walks forward from the current period by its
     * carried cycle (real month-end-clamped anchor) or, absent a cycle, by the current
     * period's own length, until a period ends on/after the cutoff.
     */
    private function renewalDueDate(Subscription $subscription, DateTimeImmutable $retiresAt): DateTimeImmutable
    {
        $period = $subscription->period;
        $cycle = $subscription->cycle;

        for ($i = 0; $i < self::MAX_PERIODS_AHEAD && $period->end < $retiresAt; $i++) {
            $period = $cycle !== null
                ? $cycle->nextPeriod($period->end)
                : $this->periodAfter($period);
        }

        return $period->end;
    }

    /** The next fixed-length period after `$period`, when the subscription carries no cycle. */
    private function periodAfter(BillingPeriod $period): BillingPeriod
    {
        $length = $period->start->diff($period->end);

        return new BillingPeriod($period->end, $period->end->add($length));
    }
}
