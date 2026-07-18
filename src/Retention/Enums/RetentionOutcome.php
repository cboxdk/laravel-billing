<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Enums;

use Cbox\Billing\Retention\Events\RetentionResolved;

/**
 * How a cancellation request ultimately resolved, recorded on
 * {@see RetentionResolved} so the host/plugin (and
 * reporting) knows whether the subscriber was saved:
 *
 *  - {@see Canceled}     — the subscriber went through with the cancellation.
 *  - {@see SavedByOffer} — the subscriber accepted a save-offer and stayed.
 *  - {@see Deferred}     — the subscriber neither cancelled nor accepted (backed out /
 *                          postponed); no state change was made.
 */
enum RetentionOutcome: string
{
    case Canceled = 'canceled';
    case SavedByOffer = 'saved_by_offer';
    case Deferred = 'deferred';
}
