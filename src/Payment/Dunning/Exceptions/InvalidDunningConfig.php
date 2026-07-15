<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Exceptions;

use InvalidArgumentException;

/**
 * Raised when the dunning knob set is nonsensical — the deny-by-default guard on the
 * policy's configuration. A cadence of zero days, a negative reminder count, or a
 * suspend threshold below one day would silently disable or corrupt escalation, so the
 * config refuses to construct rather than run with a meaningless value.
 */
class InvalidDunningConfig extends InvalidArgumentException
{
    public static function nonPositive(string $knob, int $value): self
    {
        return new self(sprintf('Dunning knob [%s] must be at least 1; got %d.', $knob, $value));
    }

    public static function negative(string $knob, int $value): self
    {
        return new self(sprintf('Dunning knob [%s] must not be negative; got %d.', $knob, $value));
    }
}
