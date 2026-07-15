<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use DateTimeImmutable;

/**
 * Produces the confirmable consequence of a plan change. Upgrade: a prorated
 * charge now, taxed through the quote engine, effective immediately. Downgrade:
 * scheduled at period end with nothing due now (the "downgrade at period end,
 * no refund" policy). This is the upgrade/downgrade/addon consequence-preview that
 * many billing platforms get wrong.
 */
readonly class PlanChangePreviewer
{
    public function __construct(
        private ProrationCalculator $proration,
        private QuoteBuilder $quotes,
    ) {}

    public function preview(
        Money $currentPrice,
        Money $newPrice,
        BillingPeriod $period,
        DateTimeImmutable $at,
        QuoteContext $context,
        string $description = 'Plan change',
    ): PlanChangePreview {
        $isUpgrade = $newPrice->compareTo($currentPrice) > 0;

        if ($isUpgrade) {
            $proratedNet = $this->proration->prorate($currentPrice, $newPrice, $period, $at);

            $quote = $this->quotes->build(
                [new LineInput($description, 1, $proratedNet)],
                $context,
            );

            return new PlanChangePreview($isUpgrade, $proratedNet, $quote, $newPrice, $at);
        }

        // Downgrade: takes effect at period end, nothing due now.
        return new PlanChangePreview(false, Money::zero($newPrice->currency()), null, $newPrice, $period->end);
    }
}
