<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Enums;

/**
 * The kind of save-offer presented to a subscriber who is cancelling. Each kind maps
 * to a lever the engine already owns, so a host (or the private retention plugin) can
 * enact an accepted offer through existing services rather than a bespoke path:
 *
 *  - {@see FreeMonth} — a run of free months (the credit-grant / free-period lever).
 *  - {@see Discount}  — a percentage off for a number of cycles (the coupon lever).
 *  - {@see Pause}     — pause the subscription for a number of cycles (the pause lever).
 *  - {@see Downgrade} — move onto a cheaper target plan/price (the plan-change lever).
 *  - {@see Custom}    — host-handled, opaque to the engine (e.g. "offer a call").
 */
enum SaveOfferType: string
{
    case FreeMonth = 'free_month';
    case Discount = 'discount';
    case Pause = 'pause';
    case Downgrade = 'downgrade';
    case Custom = 'custom';
}
