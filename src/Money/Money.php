<?php

declare(strict_types=1);

namespace Cbox\Billing\Money;

use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;

/**
 * The platform's money value object — integer minor units + ISO currency, never
 * floats. A thin wrapper over brick/money (which owns the hard parts: minor-unit
 * arithmetic, rounding modes, allocation without losing cents) so the rest of the
 * codebase depends on OUR type and the implementation stays swappable. Immutable:
 * every operation returns a new instance.
 */
readonly class Money
{
    private function __construct(private BrickMoney $money) {}

    public static function ofMinor(int $minor, string $currency): self
    {
        return new self(BrickMoney::ofMinor($minor, $currency));
    }

    public static function zero(string $currency): self
    {
        return new self(BrickMoney::zero($currency));
    }

    /** Wrap a brick/money instance — the interop seam for packages that speak brick (e.g. tax). */
    public static function fromBrick(BrickMoney $money): self
    {
        return new self($money);
    }

    /** The underlying brick/money instance, for handing to a brick-speaking collaborator. */
    public function toBrick(): BrickMoney
    {
        return $this->money;
    }

    /** Multiply by an integer factor (e.g. a line quantity) — exact, no rounding. */
    public function multipliedBy(int $factor): self
    {
        return new self($this->money->multipliedBy($factor));
    }

    /** Proportional share `numerator/denominator` (e.g. days remaining / days in period), rounded half-up. */
    public function proratedBy(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Cannot prorate by a zero denominator.');
        }

        return new self($this->money->multipliedBy($numerator)->dividedBy($denominator, RoundingMode::HalfUp));
    }

    /**
     * Split an integer `$total` into `$parts` whole units as evenly as possible,
     * distributing the remainder one unit at a time to the earliest parts — the
     * largest-remainder method for equal weights. The parts sum to `$total` EXACTLY
     * (no unit dropped or duplicated), mirroring brick/money's cent-safe allocation
     * but for bare integer units (a meter's units or minor money units). This is the
     * drift-free split ADR-0014 requires to distribute a billing-period total across
     * a variable number of cadence slices (leap years, 30/31-day months).
     *
     * @return list<int> exactly `$parts` values (empty when `$parts <= 0`) summing to `$total`
     */
    public static function allocate(int $total, int $parts): array
    {
        if ($parts <= 0) {
            return [];
        }

        // intdiv rounds toward zero; the remainder carries the sign of $total, so a
        // negative total spreads its remainder the same way (never stranding a unit).
        $base = intdiv($total, $parts);
        $remainder = $total - $base * $parts;
        $step = $remainder <=> 0;
        $extra = abs($remainder);

        $slices = [];
        for ($i = 0; $i < $parts; $i++) {
            $slices[] = $base + ($i < $extra ? $step : 0);
        }

        return $slices;
    }

    /** The amount in integer minor units (e.g. cents). */
    public function minor(): int
    {
        return $this->money->getMinorAmount()->toInt();
    }

    public function currency(): string
    {
        return $this->money->getCurrency()->getCurrencyCode();
    }

    public function plus(self $other): self
    {
        return new self($this->money->plus($other->money));
    }

    public function minus(self $other): self
    {
        return new self($this->money->minus($other->money));
    }

    public function negated(): self
    {
        return new self($this->money->multipliedBy(-1));
    }

    public function isZero(): bool
    {
        return $this->money->isZero();
    }

    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->money->isPositive();
    }

    /** -1, 0 or 1 — throws on a currency mismatch (brick/money). */
    public function compareTo(self $other): int
    {
        return $this->money->compareTo($other->money);
    }

    public function equals(self $other): bool
    {
        return $this->money->isEqualTo($other->money);
    }

    public function __toString(): string
    {
        return (string) $this->money;
    }
}
