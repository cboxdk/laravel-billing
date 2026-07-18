<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Events;

use Cbox\Billing\Retention\Enums\RetentionOutcome;
use Cbox\Billing\Retention\RetentionRecorder;
use Cbox\Billing\Retention\ValueObjects\CancellationResponse;
use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * A cancellation request reached a terminal resolution — dispatched by
 * {@see RetentionRecorder::resolved()} once the flow settles, so reporting (churn / save-rate)
 * and any follow-up automation know how it ended. The `$outcome` is one of
 * {@see RetentionOutcome}: the subscriber cancelled, was saved by an offer, or deferred.
 *
 * Carries the `$subscription`, the `$outcome`, and the subscriber's `$response` (their reason
 * + comment) when there was one, so a churn report can attribute the outcome to a reason.
 */
readonly class RetentionResolved
{
    public function __construct(
        public Subscription $subscription,
        public RetentionOutcome $outcome,
        public ?CancellationResponse $response = null,
    ) {}
}
