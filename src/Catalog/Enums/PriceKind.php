<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

use Cbox\Billing\Catalog\ValueObjects\Term;

/**
 * The commercial kind of a price point (ADR-0015). Recurring and metered products only
 * ever use `Standard`; a fixed-term (registrar-style) product publishes a distinct price
 * per kind, so registering, renewing, transferring in, or redeeming the same product for
 * the same {@see Term} can each cost a different amount:
 *
 *  - `Standard`   — the ordinary price (the default for recurring/metered/one-time).
 *  - `Register`   — first acquisition of a fixed-term instance.
 *  - `Renewal`    — extending an existing instance for another term.
 *  - `Transfer`   — bringing an instance in from elsewhere (transfer-in).
 *  - `Redemption` — recovering an instance from the post-expiry redemption window.
 */
enum PriceKind: string
{
    case Standard = 'standard';
    case Register = 'register';
    case Renewal = 'renewal';
    case Transfer = 'transfer';
    case Redemption = 'redemption';
}
