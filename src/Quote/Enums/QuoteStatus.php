<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\Enums;

/**
 * Lifecycle of a quote. A `Draft` is the shown, confirmable preview; confirming it
 * turns the shown price into the charged price (`Confirmed`); an unconfirmed quote
 * past its validity is `Expired`.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
}
