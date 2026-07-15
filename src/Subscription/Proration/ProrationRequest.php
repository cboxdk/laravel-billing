<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Proration;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use DateTimeImmutable;

/**
 * The complete, explicit input to a proration. Building the same request and running
 * it through {@see ProrationCalculator::compute()} always yields the same
 * {@see Proration} — this is the object the previewer and the charger share.
 *
 * `currentPrice` is null when entering from pay-as-you-go: there is no committed base
 * to credit, so a reset charges a full fresh period with nothing netted back.
 */
readonly class ProrationRequest
{
    public function __construct(
        public ?Money $currentPrice,
        public Money $newPrice,
        public BillingPeriod $period,
        public DateTimeImmutable $at,
        public AnchorMode $anchor = AnchorMode::Keep,
        public GatewayRounding $rounding = GatewayRounding::HalfUp,
    ) {}

    /** True when a kept anchor lowers the price — deferred to the period end, no money now. */
    public function isDeferredDowngrade(): bool
    {
        return $this->anchor === AnchorMode::Keep
            && $this->currentPrice !== null
            && $this->newPrice->compareTo($this->currentPrice) < 0;
    }
}
