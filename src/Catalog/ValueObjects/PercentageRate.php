<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use Cbox\Billing\Catalog\Exceptions\MalformedPercentagePrice;

/**
 * A percentage, held exactly as an integer count of parts per million of the base: 1.5% is
 * 15,000, 0.0001% is 1. Four decimals of a percent is the finest rate it holds, and 100% the
 * highest, so a charge is an exact integer product and a rate never passes through a float.
 */
final readonly class PercentageRate
{
    public const int MAX = 1_000_000;

    private function __construct(public int $partsPerMillion) {}

    public static function ofPartsPerMillion(int $partsPerMillion): self
    {
        if ($partsPerMillion < 0 || $partsPerMillion > self::MAX) {
            throw MalformedPercentagePrice::partsPerMillion($partsPerMillion);
        }

        return new self($partsPerMillion);
    }

    /** A percentage as a decimal string: `'1.5'` is 1.5%. Never a float, never more than four decimals. */
    public static function ofPercent(string $percent): self
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $percent, $m) !== 1) {
            throw MalformedPercentagePrice::rate($percent);
        }

        $ppm = (int) $m[1] * 10_000 + (int) str_pad($m[2] ?? '', 4, '0');

        if ($ppm > self::MAX) {
            throw MalformedPercentagePrice::rate($percent);
        }

        return new self($ppm);
    }

    /** The percentage as the shortest decimal string: `'1.5'`, `'0.0001'`, `'2'`. */
    public function toPercentString(): string
    {
        $whole = intdiv($this->partsPerMillion, 10_000);
        $fraction = rtrim(str_pad((string) ($this->partsPerMillion % 10_000), 4, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole.'.'.$fraction;
    }

    public function isZero(): bool
    {
        return $this->partsPerMillion === 0;
    }
}
