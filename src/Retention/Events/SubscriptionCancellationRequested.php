<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Events;

use Cbox\Billing\Retention\RetentionRecorder;
use Cbox\Billing\Retention\ValueObjects\CancellationResponse;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * A subscriber *requested* to cancel a subscription — dispatched by
 * {@see RetentionRecorder::cancellationRequested()} at the host's cancel path, before any
 * state change, so the retention plugin (or a host automation) can react: record the reason,
 * decide which save-offers to surface, kick off a win-back flow.
 *
 * It is a signal, not a command: emitting it does NOT itself cancel or mutate the
 * subscription — the {@see SubscriptionManager} cancel transitions
 * are unchanged. Carries the `$subscription`, the billing `$account`, and the subscriber's
 * `$response` (their picked reason + comment), which is null for a plain cancel with no survey.
 */
readonly class SubscriptionCancellationRequested
{
    public function __construct(
        public Subscription $subscription,
        public string $account,
        public ?CancellationResponse $response = null,
    ) {}
}
