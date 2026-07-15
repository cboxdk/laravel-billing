<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use Cbox\Billing\Subscription\Proration\Proration;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\Proration\ProrationRequest;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use DateTimeImmutable;

/**
 * Produces the confirmable consequence of a plan change. It owns no proration
 * arithmetic of its own: it hands the inputs to {@see ProrationCalculator::compute()}
 * — the same call the charge path makes — and only taxes the amount that call says is
 * due now. Because the numbers come from that single source, the preview equals the
 * charge by construction rather than by keeping two paths in step.
 */
readonly class PlanChangePreviewer
{
    public function __construct(
        private ProrationCalculator $proration,
        private QuoteBuilder $quotes,
    ) {}

    public function preview(
        ?Money $currentPrice,
        Money $newPrice,
        BillingPeriod $period,
        DateTimeImmutable $at,
        QuoteContext $context,
        string $description = 'Plan change',
        AnchorMode $anchor = AnchorMode::Keep,
        GatewayRounding $rounding = GatewayRounding::HalfUp,
    ): PlanChangePreview {
        $proration = $this->proration->compute(new ProrationRequest(
            currentPrice: $currentPrice,
            newPrice: $newPrice,
            period: $period,
            at: $at,
            anchor: $anchor,
            rounding: $rounding,
        ));

        return new PlanChangePreview(
            isUpgrade: ! $proration->deferred && $proration->net->isPositive(),
            proratedNet: $proration->net,
            dueNowQuote: $this->dueNowQuote($proration, $context, $description),
            newRecurring: $newPrice,
            effectiveAt: $proration->effectiveAt,
            proration: $proration,
        );
    }

    /** Tax the amount due now, if any; a deferred change or a net credit is not quoted. */
    private function dueNowQuote(Proration $proration, QuoteContext $context, string $description): ?Quote
    {
        $dueNow = $proration->dueNow();

        if (! $dueNow->isPositive()) {
            return null;
        }

        return $this->quotes->build([new LineInput($description, 1, $dueNow)], $context);
    }
}
