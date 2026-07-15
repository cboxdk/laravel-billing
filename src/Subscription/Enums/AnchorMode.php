<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

/**
 * Whether a plan change keeps the current renewal anchor or resets it.
 *
 * - `Keep`: the billing date is preserved. An upgrade prorates only the price
 *   *delta* over the remaining period; a downgrade is deferred to the period end.
 * - `Reset`: the period restarts now. A fresh full period is charged at the new
 *   price and the unused part of the current base is netted as a credit — which can
 *   exceed the fresh price and leave a net credit.
 */
enum AnchorMode: string
{
    case Keep = 'keep';
    case Reset = 'reset';
}
