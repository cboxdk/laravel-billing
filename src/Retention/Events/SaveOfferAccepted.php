<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Events;

use Cbox\Billing\Retention\RetentionRecorder;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;
use Cbox\Billing\Subscription\ValueObjects\Subscription;

/**
 * A cancelling subscriber accepted a save-offer — dispatched by
 * {@see RetentionRecorder::offerAccepted()}. The retention plugin / host listens and enacts
 * the offer through the lever it maps to (a credit grant for a free month, a coupon for a
 * discount, a pause, a plan change for a downgrade, or a host-defined action for a custom
 * offer); the engine only records that it was accepted, it does not enact it here.
 *
 * Carries the `$subscription` and the `$offer` that was accepted (with its typed params).
 */
readonly class SaveOfferAccepted
{
    public function __construct(
        public Subscription $subscription,
        public SaveOffer $offer,
    ) {}
}
