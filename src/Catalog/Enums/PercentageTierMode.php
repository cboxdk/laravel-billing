<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

use Cbox\Billing\Catalog\ValueObjects\PercentageTier;

/**
 * How a {@see PricingModel::Percentage} price reads its {@see PercentageTier}s.
 *
 *  - `Graduated` — the volume is sliced across the tiers and each slice is charged its own tier's
 *                  rate (the first 10,000.00 at 2%, what is above at 1%).
 *  - `Volume`    — all of the volume is charged the rate of the tier the total lands in.
 */
enum PercentageTierMode: string
{
    case Graduated = 'graduated';
    case Volume = 'volume';
}
