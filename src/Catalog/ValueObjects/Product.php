<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\ValueObjects;

use Cbox\Billing\Catalog\Enums\PlanStatus;
use Cbox\Billing\Catalog\Enums\ProductShape;
use DateTimeImmutable;

/**
 * A sellable product — the catalog's notion of a plan. Prices are versioned
 * separately (Product/Price split), so a product's price can change over time
 * without changing the product.
 *
 * A plan declares a {@see $family} — a stable key grouping plans that a subscription
 * may move between freely (e.g. `hosted`, `on-prem`, `payg`, `unlimited`) — and a
 * {@see PlanStatus} recording whether it is still offered or only grandfathered.
 * Deny-by-default: a plan that declares no family is treated as **its own singleton
 * family** (its id), so it shares a family with nothing else and every cross-family
 * rule applies to it (ADR-0010).
 *
 * A product also declares a {@see ProductShape} selecting its billing/fulfilment
 * semantics — metered, rolling recurring, fixed-term (registrar-style), or one-time
 * (ADR-0015). The shape defaults to {@see ProductShape::Recurring} so existing
 * catalogs keep their meaning; a registrar-style product is `FixedTerm` and its
 * catalog is a set of (term × price-kind) price points.
 *
 * A plan may finally carry an optional {@see PlanRetirement} — a hard sunset cutoff
 * with an optional default successor — after which its existing subscribers are forced
 * to resolve off it at their next renewal (ADR-0016). It rides on the trailing
 * constructor argument so the value object stays backward-compatible.
 */
readonly class Product
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $family = null,
        public PlanStatus $status = PlanStatus::Offered,
        public ProductShape $shape = ProductShape::Recurring,
        public ?PlanRetirement $retirement = null,
    ) {}

    /** A fixed-term (registrar-style) product: bought for a committed {@see Term} with per-kind pricing. */
    public function isFixedTerm(): bool
    {
        return $this->shape === ProductShape::FixedTerm;
    }

    /**
     * The family this plan belongs to. Falls back to the plan's own id when none is
     * declared, so an unfamilied plan is a singleton family — deny-by-default against
     * cross-family moves rather than silently grouped with anything.
     */
    public function family(): string
    {
        return $this->family ?? $this->id;
    }

    /** Grandfathered: a valid transition source but never a target. */
    public function isLegacy(): bool
    {
        return $this->status === PlanStatus::Legacy;
    }

    /** Still in the current catalog: a valid transition source and target. */
    public function isOffered(): bool
    {
        return $this->status === PlanStatus::Offered;
    }

    /**
     * Whether the plan is being retired (ADR-0016) — it carries a {@see PlanRetirement}
     * cutoff, or is explicitly flagged {@see PlanStatus::Retiring}. A being-retired plan
     * is never a valid transition **target**: no subscription may switch *onto* it.
     */
    public function isBeingRetired(): bool
    {
        return $this->retirement !== null || $this->status === PlanStatus::Retiring;
    }

    /**
     * Whether the plan is retired **as of `$at`** — it carries a cutoff that has passed.
     * A plan flagged `Retiring` with no cutoff date is considered being-retired but not
     * yet past a cutoff, so this is false for it (there is no date to compare).
     */
    public function isRetiring(DateTimeImmutable $at): bool
    {
        return $this->retirement !== null && $this->retirement->isRetiredAt($at);
    }

    /** The plan's retirement cutoff, or null when it is not being sunset. */
    public function retiresAt(): ?DateTimeImmutable
    {
        return $this->retirement?->retiresAt;
    }

    /** Two plans share a family when their (defaulted) family keys match. */
    public function sameFamilyAs(self $other): bool
    {
        return $this->family() === $other->family();
    }
}
