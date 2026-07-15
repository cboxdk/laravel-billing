<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Enums;

/**
 * How a plan sizes a credit grant:
 *  - Base    — a flat allotment per grant, independent of seat count.
 *  - PerSeat — an allotment granted per additional seat on the subscription.
 */
enum GrantKind: string
{
    case Base = 'base';
    case PerSeat = 'per_seat';
}
