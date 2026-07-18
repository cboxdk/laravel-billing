<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use DateTimeImmutable;

/**
 * A pending change to a subscription's price, taking effect at a future date. It
 * is mutable until then — it can be replaced or cleared before it applies.
 *
 * It optionally also carries a `$newProductId` — a scheduled **plan** (product) change,
 * not just a price change. When null the change re-pins only the price within the same
 * product (the original behaviour); when set, applying the change also moves the
 * subscription onto the new product. A scheduled plan change is how a subscriber elects
 * a **successor** ahead of a plan's retirement (ADR-0016). It rides on the trailing
 * constructor argument so the value object stays backward-compatible.
 */
readonly class ScheduledChange
{
    public function __construct(
        public string $newPriceId,
        public DateTimeImmutable $effectiveAt,
        public ?string $newProductId = null,
    ) {}

    /** Whether this change also moves the subscription onto a different product (a plan change). */
    public function changesPlan(): bool
    {
        return $this->newProductId !== null;
    }
}
