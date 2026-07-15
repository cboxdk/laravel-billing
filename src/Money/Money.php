<?php

declare(strict_types=1);

namespace Cbox\Billing\Money;

use Brick\Money\Money as BrickMoney;

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
