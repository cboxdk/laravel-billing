<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention;

use Cbox\Billing\Retention\Enums\RetentionOutcome;
use Cbox\Billing\Retention\Events\RetentionResolved;
use Cbox\Billing\Retention\Events\SaveOfferAccepted;
use Cbox\Billing\Retention\Events\SubscriptionCancellationRequested;
use Cbox\Billing\Retention\ValueObjects\CancellationResponse;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The thin seam the host calls at its cancel path to emit the retention domain events, so a
 * plugin or automation reacts by listening rather than by the engine calling it. It records
 * three moments — the cancel was *requested*, a save-offer was *accepted*, the flow *resolved*
 * — and emits nothing else; it does not itself cancel, pause, discount, or grant. Deciding
 * *when* to call it (and enacting an accepted offer through the real levers) is the host's job.
 *
 * The dispatcher is an optional, trailing dependency — the same no-BC pattern the
 * {@see SubscriptionManager} uses — so a direct instantiation with
 * no dispatcher simply emits nothing, and the default binding stays inert without a plugin.
 */
readonly class RetentionRecorder
{
    public function __construct(
        private ?Dispatcher $events = null,
    ) {}

    /**
     * Record that a cancel was requested for `$subscription` on `$account`, carrying the
     * subscriber's `$response` (their picked reason + comment) when a survey produced one.
     * Emitted before any state change, so a listener can react while the subscription still
     * serves.
     */
    public function cancellationRequested(Subscription $subscription, string $account, ?CancellationResponse $response = null): void
    {
        $this->events?->dispatch(new SubscriptionCancellationRequested($subscription, $account, $response));
    }

    /** Record that the subscriber accepted `$offer` on `$subscription`. */
    public function offerAccepted(Subscription $subscription, SaveOffer $offer): void
    {
        $this->events?->dispatch(new SaveOfferAccepted($subscription, $offer));
    }

    /**
     * Record that the cancellation request reached `$outcome`, carrying the `$response` when
     * there was one so reporting can attribute the outcome to a reason.
     */
    public function resolved(Subscription $subscription, RetentionOutcome $outcome, ?CancellationResponse $response = null): void
    {
        $this->events?->dispatch(new RetentionResolved($subscription, $outcome, $response));
    }
}
