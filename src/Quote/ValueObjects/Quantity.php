<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

use Cbox\Billing\Quote\Exceptions\InvalidQuantity;

/**
 * A positive line quantity with up to three decimals (e.g. 12.5 hours), held as an
 * integer count of thousandths so no float ever touches a price. Parsing works on the
 * digits of the input — more than three decimals is refused, never rounded.
 */
readonly class Quantity
{
    public const int SCALE = 3;

    /** 9,999,999,999,999.999 (the most a decimal(16,3) column holds) in thousandths. */
    public const int MAX_SCALED = 9_999_999_999_999_999;

    private const int FACTOR = 1000;

    private function __construct(private int $scaled) {}

    public static function of(int|string $value): self
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw InvalidQuantity::nonPositive((string) $value);
            }
            if ($value > intdiv(self::MAX_SCALED, self::FACTOR)) {
                throw InvalidQuantity::tooLarge((string) $value);
            }

            return new self($value * self::FACTOR);
        }

        $given = trim($value);

        if (preg_match('/^-\d+(\.\d+)?$/', $given) === 1) {
            throw InvalidQuantity::nonPositive($given);
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $given, $m) !== 1) {
            throw InvalidQuantity::malformed($given);
        }

        $fraction = $m[2] ?? '';
        if (strlen($fraction) > self::SCALE) {
            throw InvalidQuantity::tooPrecise($given);
        }

        $whole = ltrim($m[1], '0');
        if (strlen($whole) > 13) {
            throw InvalidQuantity::tooLarge($given);
        }

        $scaled = ((int) ($whole === '' ? '0' : $whole)) * self::FACTOR
            + (int) str_pad($fraction, self::SCALE, '0');

        if ($scaled === 0) {
            throw InvalidQuantity::nonPositive($given);
        }
        if ($scaled > self::MAX_SCALED) {
            throw InvalidQuantity::tooLarge($given);
        }

        return new self($scaled);
    }

    /** Build from an integer count of thousandths (12500 → 12.500). */
    public static function ofScaled(int $scaled): self
    {
        if ($scaled < 1) {
            throw InvalidQuantity::nonPositive((string) $scaled);
        }
        if ($scaled > self::MAX_SCALED) {
            throw InvalidQuantity::tooLarge((string) $scaled);
        }

        return new self($scaled);
    }

    public static function one(): self
    {
        return new self(self::FACTOR);
    }

    /** The quantity in thousandths. */
    public function scaled(): int
    {
        return $this->scaled;
    }

    /** Always three decimals, e.g. "12.500". */
    public function toDecimalString(): string
    {
        return intdiv($this->scaled, self::FACTOR).'.'.str_pad((string) ($this->scaled % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT);
    }

    public function isWhole(): bool
    {
        return $this->scaled % self::FACTOR === 0;
    }

    public function toInt(): int
    {
        if (! $this->isWhole()) {
            throw InvalidQuantity::notWhole($this->toDecimalString());
        }

        return intdiv($this->scaled, self::FACTOR);
    }

    public function equals(self $other): bool
    {
        return $this->scaled === $other->scaled;
    }
}
