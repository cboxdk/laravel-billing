<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Money\Money;
use DateTimeImmutable;

/**
 * A versioned price for a product, effective over a date range. Versioning is how
 * grandfathering works: a subscription pins the price effective at its start date,
 * and keeps resolving that version even after newer ones take effect.
 *
 * For a fixed-term (registrar-style) product a price also carries a {@see Term} and a
 * {@see PriceKind}, so the catalog holds one price point per (term × kind) — e.g.
 * `P2Y`/`Register` distinct from `P1Y`/`Renewal` — each still grandfathered by
 * effective date (ADR-0015). Recurring/metered prices leave `term` null and `kind`
 * at {@see PriceKind::Standard}.
 */
readonly class Price
{
    public function __construct(
        public string $id,
        public string $productId,
        public PricingModel $model,
        public Money $unitAmount,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil = null,
        public ?Term $term = null,
        public PriceKind $kind = PriceKind::Standard,
    ) {}

    public function isEffectiveAt(DateTimeImmutable $at): bool
    {
        return $at >= $this->effectiveFrom
            && ($this->effectiveUntil === null || $at < $this->effectiveUntil);
    }

    /** The quantity actually billed: always 1 for flat pricing, the requested amount for per-unit. */
    public function billableQuantity(int $requested): int
    {
        return $this->model === PricingModel::Flat ? 1 : $requested;
    }

    public function amountFor(int $quantity): Money
    {
        return $this->unitAmount->multipliedBy($this->billableQuantity($quantity));
    }
}
