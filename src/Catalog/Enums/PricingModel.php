<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

/**
 * How a price turns a quantity into an amount.
 *
 *  - `Flat`    — a fixed amount regardless of quantity (billable quantity is 1).
 *  - `PerUnit` — the unit amount times the quantity (per seat, per unit).
 *
 * Tiered (volume / graduated) and pure usage models build on the same shape and
 * land with the subscriptions/metering wiring.
 */
enum PricingModel: string
{
    case Flat = 'flat';
    case PerUnit = 'per_unit';
}
