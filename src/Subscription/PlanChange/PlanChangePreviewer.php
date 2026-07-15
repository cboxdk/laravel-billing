<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use Cbox\Billing\Subscription\PlanChange\Exceptions\TransitionNotAllowed;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditConsequenceRequest;
use Cbox\Billing\Subscription\Proration\Proration;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\Proration\ProrationRequest;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use DateTimeImmutable;

/**
 * Produces the confirmable consequence of a plan change. It gates on the
 * {@see TransitionPolicy} **before** touching proration: a disallowed target raises
 * {@see TransitionNotAllowed} with the policy's reason — never a silent proration
 * (ADR-0010). For an allowed change it owns no proration arithmetic of its own: it
 * hands the inputs to {@see ProrationCalculator::compute()} — the same call the charge
 * path makes — and only taxes the amount that call says is due now, so the preview
 * equals the charge by construction.
 *
 * Alongside the money it projects the credit consequence (ADR-0011) via the
 * {@see CreditConsequenceCalculator} from the caller-supplied wallet figures, and warns
 * when the current plan is legacy (the change cannot be reversed).
 */
readonly class PlanChangePreviewer
{
    public function __construct(
        private ProrationCalculator $proration,
        private QuoteBuilder $quotes,
        private TransitionPolicy $policy,
        private CreditConsequenceCalculator $credit = new CreditConsequenceCalculator,
    ) {}

    public function preview(
        Product $fromPlan,
        Product $toPlan,
        ?Money $currentPrice,
        Money $newPrice,
        BillingPeriod $period,
        DateTimeImmutable $at,
        QuoteContext $context,
        CreditConsequenceRequest $credit = new CreditConsequenceRequest,
        string $description = 'Plan change',
        AnchorMode $anchor = AnchorMode::Keep,
        GatewayRounding $rounding = GatewayRounding::HalfUp,
    ): PlanChangePreview {
        $decision = $this->policy->canTransition($fromPlan, $toPlan);

        if (! $decision->isAllowed()) {
            throw TransitionNotAllowed::because($fromPlan, $toPlan, (string) $decision->reason);
        }

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
            creditDelta: $this->credit->forSwitch($credit, $decision->carryOver),
            irreversibilityWarning: $fromPlan->isLegacy()
                ? "You are on a legacy plan [{$fromPlan->id}]; changing means you cannot switch back to it."
                : null,
            guidance: $decision->guidance,
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
