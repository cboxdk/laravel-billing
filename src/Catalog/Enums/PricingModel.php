<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

use Cbox\Billing\Catalog\Pricing\TierCalculator;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;

/**
 * How a price turns a quantity into an amount.
 *
 *  - `Flat`      — a fixed amount regardless of quantity (billable quantity is 1).
 *  - `PerUnit`   — the unit amount times the quantity (per seat, per unit).
 *  - `Graduated` — the quantity is sliced across ordered {@see PriceTier}s and each
 *                  slice is priced at ITS tier's unit rate; the charge is the sum
 *                  across tiers (plus any per-tier flat fee that the quantity reaches).
 *  - `Volume`    — ALL units are priced at the single tier the TOTAL quantity lands
 *                  in — the whole quantity gets that one tier's rate (a volume
 *                  discount that applies retroactively to every unit).
 *  - `Package`   — a block price per `packageSize` units: `ceil(qty / size)` whole
 *                  blocks, each charged the block's flat amount (buy in packs of N).
 *  - `Stairstep` — a single flat amount for the whole bracket the quantity lands in;
 *                  the price steps up bracket-by-bracket, never per unit.
 *
 * The tiered models are computed by {@see TierCalculator}, remainder-safe in integer
 * minor units and deny-by-default on a malformed tier set.
 */
enum PricingModel: string
{
    case Flat = 'flat';
    case PerUnit = 'per_unit';
    case Graduated = 'graduated';
    case Volume = 'volume';
    case Package = 'package';
    case Stairstep = 'stairstep';

    /** True for the models priced through {@see TierCalculator} (they carry a tier set). */
    public function isTiered(): bool
    {
        return match ($this) {
            self::Flat, self::PerUnit => false,
            self::Graduated, self::Volume, self::Package, self::Stairstep => true,
        };
    }
}
