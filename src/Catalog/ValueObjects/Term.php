<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\Enums\ProductShape;
use Cbox\Billing\Catalog\Enums\TermUnit;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A fixed-term duration — the committed length a {@see ProductShape}::FixedTerm
 * product is bought for (e.g. 1, 2, or 5 years). Immutable value object (ADR-0015).
 *
 * A fixed-term catalog is a set of (term × {@see PriceKind}) price
 * points, so the term is a pricing dimension alongside the kind. Term arithmetic uses the
 * calendar (`DateInterval`), so a 1-year term added to Feb 29 lands via PHP's month/year
 * rollover — the commercial term length, not a fixed day count.
 */
readonly class Term
{
    public function __construct(
        public int $count,
        public TermUnit $unit,
    ) {
        if ($count <= 0) {
            throw new InvalidArgumentException('A term must span a positive count.');
        }
    }

    /** Add this term to a date, returning the term end. */
    public function addTo(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->add(new DateInterval($this->toIso8601()));
    }

    /** The ISO-8601 duration, e.g. `P2Y`, `P6M`, `P30D`. */
    public function toIso8601(): string
    {
        return 'P'.$this->count.$this->unit->iso8601Designator();
    }

    /** Two terms are equal when both count and unit match (a term is a pricing key). */
    public function equals(self $other): bool
    {
        return $this->count === $other->count && $this->unit === $other->unit;
    }
}
