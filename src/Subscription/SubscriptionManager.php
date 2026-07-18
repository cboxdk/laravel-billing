<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Events\SubscriptionChanged;
use Cbox\Billing\Events\SubscriptionRenewed;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\Exceptions\IllegalStateTransition;
use Cbox\Billing\Subscription\ValueObjects\AddOn;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\MinimumCommitment;
use Cbox\Billing\Subscription\ValueObjects\RampSchedule;
use Cbox\Billing\Subscription\ValueObjects\ScheduledChange;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Pure lifecycle transitions for a subscription — each returns a new immutable
 * instance. The full state machine lives here: a subscription may open in a trial,
 * convert to paying, fail and recover a payment, pause and resume, schedule a
 * period-end cancellation, or cancel immediately.
 *
 * The machine is **deny-by-default**: every status transition is checked against
 * {@see allowedTransitions()} and an illegal one throws {@see IllegalStateTransition}
 * rather than being silently applied. Renewal advances the period, enacts a due
 * cancellation, and applies a due scheduled change — and honours `Paused`/`Canceled`
 * (a renewal touches neither) and `NonRenewing` (a renewal enacts the cancel).
 */
readonly class SubscriptionManager
{
    public function __construct(
        private ?Dispatcher $events = null,
    ) {}

    /**
     * Create a subscription. With no `$trialEndsAt` it opens `Active` (the original
     * behaviour); with a `$trialEndsAt` it opens `Trialing` and carries the trial end,
     * charging nothing until {@see convertTrial()}.
     */
    public function create(string $id, string $organizationId, string $productId, string $priceId, BillingPeriod $period, ?DateTimeImmutable $trialEndsAt = null): Subscription
    {
        return new Subscription(
            $id,
            $organizationId,
            $productId,
            $priceId,
            $period,
            status: $trialEndsAt !== null ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            trialEndsAt: $trialEndsAt,
        );
    }

    /**
     * Open a subscription in a trial that ends at `$trialEndsAt` — the explicit
     * trial-first counterpart to {@see create()}. It is `Trialing` and charges nothing
     * until {@see convertTrial()} converts it (first charge) at trial end.
     */
    public function startTrial(string $id, string $organizationId, string $productId, string $priceId, BillingPeriod $period, DateTimeImmutable $trialEndsAt): Subscription
    {
        return $this->create($id, $organizationId, $productId, $priceId, $period, $trialEndsAt);
    }

    /**
     * Create a subscription anchored on a {@see BillingCycle}: the opening period is the
     * cycle's period containing `$startAt`, and the cycle is carried so renewals advance
     * on the real month-end-clamped anchor rather than an assumed window (ADR-0012). A
     * `$trialEndsAt` opens it `Trialing`, exactly as {@see create()}.
     */
    public function createOnCycle(string $id, string $organizationId, string $productId, string $priceId, BillingCycle $cycle, DateTimeImmutable $startAt, ?DateTimeImmutable $trialEndsAt = null): Subscription
    {
        return new Subscription(
            $id,
            $organizationId,
            $productId,
            $priceId,
            $cycle->periodContaining($startAt),
            status: $trialEndsAt !== null ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            cycle: $cycle,
            trialEndsAt: $trialEndsAt,
        );
    }

    /**
     * Convert a trial to a paying subscription (first charge): `Trialing` → `Active`.
     * The pure engine only flips the state and clears the trial marker; the first charge
     * is raised by the invoice/renewal path that observes the transition.
     */
    public function convertTrial(Subscription $subscription, DateTimeImmutable $at): Subscription
    {
        $this->assertTransition($subscription->status, SubscriptionStatus::Active);

        return $this->copy($subscription, status: SubscriptionStatus::Active, clearTrialEndsAt: true);
    }

    /** A payment-failure signal: move a serving subscription to `PastDue` for dunning. */
    public function markPastDue(Subscription $subscription): Subscription
    {
        $this->assertTransition($subscription->status, SubscriptionStatus::PastDue);

        return $this->copy($subscription, status: SubscriptionStatus::PastDue);
    }

    /** Payment recovered: `PastDue` → `Active`. */
    public function recover(Subscription $subscription): Subscription
    {
        $this->assertTransition($subscription->status, SubscriptionStatus::Active);

        return $this->copy($subscription, status: SubscriptionStatus::Active);
    }

    /**
     * Pause the subscription at `$at`: `Active` (or `Trialing`) → `Paused`. No billing
     * happens while paused — {@see renew()} is a no-op on a paused subscription — and the
     * pause instant is recorded so {@see resume()} can shift the period by the paused span.
     */
    public function pause(Subscription $subscription, DateTimeImmutable $at): Subscription
    {
        $this->assertTransition($subscription->status, SubscriptionStatus::Paused);

        return $this->copy($subscription, status: SubscriptionStatus::Paused, pausedAt: $at);
    }

    /**
     * Return a non-serving-but-recoverable subscription to `Active`. It dispatches on the
     * current state:
     *
     *  - `Paused`      — resume from a pause: shift the current period forward by the
     *                    paused span (`$at − pausedAt`) so no served time is lost, and
     *                    clear the pause marker. `$at` is required.
     *  - `NonRenewing` — undo a scheduled period-end cancellation (clears
     *                    `cancelAtPeriodEnd`). `$at` is ignored.
     *  - `Active`      — idempotent no-op.
     *
     * Any other source state is refused by the machine.
     */
    public function resume(Subscription $subscription, ?DateTimeImmutable $at = null): Subscription
    {
        if ($subscription->status === SubscriptionStatus::Paused) {
            $this->assertTransition($subscription->status, SubscriptionStatus::Active);

            $shifted = $subscription->period;
            $pausedAt = $subscription->pausedAt;
            if ($at !== null && $pausedAt !== null) {
                $span = $pausedAt->diff($at);
                $shifted = new BillingPeriod($subscription->period->start->add($span), $subscription->period->end->add($span));
            }

            return $this->copy($subscription, period: $shifted, status: SubscriptionStatus::Active, clearPausedAt: true);
        }

        $this->assertTransition($subscription->status, SubscriptionStatus::Active);

        return $this->copy($subscription, status: SubscriptionStatus::Active, cancelAtPeriodEnd: false);
    }

    /**
     * Schedule a period-end cancellation: the subscription becomes `NonRenewing` and keeps
     * serving until the current period renews, at which point {@see renew()} enacts the
     * cancel. Recorded both as the `NonRenewing` state and the `cancelAtPeriodEnd` flag the
     * renewal keys on.
     */
    public function cancelAtPeriodEnd(Subscription $subscription): Subscription
    {
        $this->assertTransition($subscription->status, SubscriptionStatus::NonRenewing);

        return $this->copy($subscription, status: SubscriptionStatus::NonRenewing, cancelAtPeriodEnd: true);
    }

    /**
     * Cancel immediately — the org ends on no plan right now (not at period end). This
     * is the cancel-to-null transition ADR-0006 keys forfeiture on; the pure engine
     * only produces the ended state, the lifecycle wires the forfeiture.
     */
    public function cancelNow(Subscription $subscription): Subscription
    {
        $this->assertTransition($subscription->status, SubscriptionStatus::Canceled);

        return $this->copy($subscription, status: SubscriptionStatus::Canceled, cancelAtPeriodEnd: false);
    }

    /** Schedule (or replace) a price change — mutable until it applies. */
    public function scheduleChange(Subscription $subscription, string $newPriceId, DateTimeImmutable $effectiveAt): Subscription
    {
        $change = new ScheduledChange($newPriceId, $effectiveAt);
        $updated = $this->copy($subscription, pendingChange: $change);

        $this->events?->dispatch(new SubscriptionChanged($updated, $change));

        return $updated;
    }

    /**
     * Schedule (or replace) a **plan** change — a move onto a different product (and its
     * price) at a future date, mutable until it applies. This is the price-change
     * counterpart's plan-level sibling: a subscriber elects a successor plan here, and
     * {@see renew()} enacts it when due. It is how a subscriber chooses a successor ahead
     * of a plan's retirement (ADR-0016).
     */
    public function schedulePlanChange(Subscription $subscription, string $newProductId, string $newPriceId, DateTimeImmutable $effectiveAt): Subscription
    {
        $change = new ScheduledChange($newPriceId, $effectiveAt, $newProductId);
        $updated = $this->copy($subscription, pendingChange: $change);

        $this->events?->dispatch(new SubscriptionChanged($updated, $change));

        return $updated;
    }

    public function clearScheduledChange(Subscription $subscription): Subscription
    {
        return $this->copy($subscription, clearPendingChange: true);
    }

    /** Attach (or replace) an add-on — matched by id, so re-adding one swaps it in place. */
    public function addAddOn(Subscription $subscription, AddOn $addOn): Subscription
    {
        $addOns = array_values(array_filter(
            $subscription->addOns,
            static fn (AddOn $existing): bool => $existing->id !== $addOn->id,
        ));
        $addOns[] = $addOn;

        return $this->copy($subscription, addOns: $addOns);
    }

    /** Detach an add-on by id; a no-op when it is not attached. */
    public function removeAddOn(Subscription $subscription, string $addOnId): Subscription
    {
        return $this->copy($subscription, addOns: array_values(array_filter(
            $subscription->addOns,
            static fn (AddOn $existing): bool => $existing->id !== $addOnId,
        )));
    }

    /**
     * Attach (or replace) a ramp schedule — the predetermined price steps resolved at each
     * renewal against the subscription's period index.
     */
    public function withRamp(Subscription $subscription, RampSchedule $ramp): Subscription
    {
        return $this->copy($subscription, ramp: $ramp);
    }

    /** Attach (or replace) a minimum-commitment floor producing a per-period true-up. */
    public function withMinimumCommitment(Subscription $subscription, MinimumCommitment $commitment): Subscription
    {
        return $this->copy($subscription, minimumCommitment: $commitment);
    }

    /**
     * Advance a cycle-anchored subscription onto its next period, computed from the
     * carried {@see BillingCycle} at `$at` — the renewal counterpart to
     * {@see createOnCycle()}. Falls back to a plain renewal into the caller-supplied
     * period when the subscription carries no cycle.
     */
    public function renewOnCycle(Subscription $subscription, DateTimeImmutable $at): Subscription
    {
        $cycle = $subscription->cycle;

        if ($cycle === null) {
            return $subscription;
        }

        return $this->renew($subscription, $cycle->nextPeriod($at));
    }

    /**
     * Advance to the next period: a paused or already-canceled subscription is untouched
     * (no billing); a due cancellation (`NonRenewing`/`cancelAtPeriodEnd`) ends the
     * subscription; a due scheduled change re-pins the price; otherwise the price carries
     * over. On a real advance the period index increments, so a {@see RampSchedule}
     * resolves the next step.
     */
    public function renew(Subscription $subscription, BillingPeriod $nextPeriod): Subscription
    {
        // No billing while paused, and a canceled subscription is terminal: a renewal
        // touches neither.
        if ($subscription->status === SubscriptionStatus::Paused || $subscription->status === SubscriptionStatus::Canceled) {
            return $subscription;
        }

        // A due cancellation ends the subscription rather than renewing it: this is not a
        // renewal, so no SubscriptionRenewed fires.
        if ($subscription->cancelAtPeriodEnd) {
            return $this->copy($subscription, status: SubscriptionStatus::Canceled);
        }

        $change = $subscription->pendingChange;
        $nextIndex = $subscription->periodIndex + 1;

        $renewed = $change !== null && $change->effectiveAt <= $nextPeriod->start
            ? $this->copy($subscription, productId: $change->newProductId, priceId: $change->newPriceId, period: $nextPeriod, clearPendingChange: true, periodIndex: $nextIndex)
            : $this->copy($subscription, period: $nextPeriod, periodIndex: $nextIndex);

        $this->events?->dispatch(new SubscriptionRenewed($subscription, $renewed));

        return $renewed;
    }

    /**
     * Renew a subscription **onto a different plan** — advance it into `$nextPeriod` while
     * switching it to `$newProductId` at `$newPriceId`, as `Active`, index incremented, any
     * pending change cleared. This is the enactment the retirement flow uses to migrate a
     * subscriber off a retired plan onto a validated successor (ADR-0016); it does not
     * itself gate the transition — the {@see Retirement\RetirementRenewalPolicy} validates
     * through the {@see TransitionPolicy} first.
     */
    public function renewOntoPlan(Subscription $subscription, string $newProductId, string $newPriceId, BillingPeriod $nextPeriod): Subscription
    {
        $renewed = $this->copy(
            $subscription,
            productId: $newProductId,
            priceId: $newPriceId,
            period: $nextPeriod,
            status: SubscriptionStatus::Active,
            clearPendingChange: true,
            periodIndex: $subscription->periodIndex + 1,
        );

        $this->events?->dispatch(new SubscriptionRenewed($subscription, $renewed));

        return $renewed;
    }

    /**
     * The state machine: for each state, the set of states it may move to. Any pair not
     * listed is refused (deny-by-default). `Canceled` is terminal. Self-loops are listed
     * where a transition is idempotent (a renewal keeps an `Active` subscription active;
     * resuming an already-active one is a no-op).
     *
     * @return array<string, list<SubscriptionStatus>>
     */
    private function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Trialing->value => [SubscriptionStatus::Active, SubscriptionStatus::Paused, SubscriptionStatus::PastDue, SubscriptionStatus::NonRenewing, SubscriptionStatus::Canceled],
            SubscriptionStatus::Active->value => [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Paused, SubscriptionStatus::NonRenewing, SubscriptionStatus::Canceled],
            SubscriptionStatus::PastDue->value => [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::NonRenewing, SubscriptionStatus::Canceled],
            SubscriptionStatus::Paused->value => [SubscriptionStatus::Active, SubscriptionStatus::Paused, SubscriptionStatus::Canceled],
            SubscriptionStatus::NonRenewing->value => [SubscriptionStatus::Active, SubscriptionStatus::NonRenewing, SubscriptionStatus::Canceled],
            SubscriptionStatus::Canceled->value => [],
        ];
    }

    /** Refuse a transition the machine does not permit. */
    private function assertTransition(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        if (! in_array($to, $this->allowedTransitions()[$from->value], strict: true)) {
            throw IllegalStateTransition::between($from, $to);
        }
    }

    /**
     * @param  list<AddOn>|null  $addOns
     */
    private function copy(
        Subscription $subscription,
        ?string $productId = null,
        ?string $priceId = null,
        ?BillingPeriod $period = null,
        ?SubscriptionStatus $status = null,
        ?bool $cancelAtPeriodEnd = null,
        ?ScheduledChange $pendingChange = null,
        bool $clearPendingChange = false,
        ?array $addOns = null,
        ?DateTimeImmutable $pausedAt = null,
        bool $clearPausedAt = false,
        bool $clearTrialEndsAt = false,
        ?int $periodIndex = null,
        ?RampSchedule $ramp = null,
        ?MinimumCommitment $minimumCommitment = null,
    ): Subscription {
        return new Subscription(
            $subscription->id,
            $subscription->organizationId,
            $productId ?? $subscription->productId,
            $priceId ?? $subscription->priceId,
            $period ?? $subscription->period,
            $status ?? $subscription->status,
            $cancelAtPeriodEnd ?? $subscription->cancelAtPeriodEnd,
            $clearPendingChange ? null : ($pendingChange ?? $subscription->pendingChange),
            $subscription->cycle,
            $addOns ?? $subscription->addOns,
            $clearTrialEndsAt ? null : $subscription->trialEndsAt,
            $clearPausedAt ? null : ($pausedAt ?? $subscription->pausedAt),
            $periodIndex ?? $subscription->periodIndex,
            $ramp ?? $subscription->ramp,
            $minimumCommitment ?? $subscription->minimumCommitment,
        );
    }
}
