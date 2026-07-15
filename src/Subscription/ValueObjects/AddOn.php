<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\CreditGrantMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use Cbox\Billing\Subscription\PlanChange\ProratedAllotment;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An add-on attached to a subscription (ADR-0012): an extra recurring charge with an
 * optional per-cycle credit allotment, billed either **aligned** to the base
 * subscription's cycle (default) or on its **own independent** {@see BillingCycle}.
 *
 * The alignment is the whole point of the value object: {@see periodFor()} resolves the
 * period an add-on bills and grants over — the base period when aligned, its own cycle
 * when independent — and every downstream computation (a mid-cycle add's prorated
 * charge, its credit allotment) derives from that one period, so both modes flow
 * through the same proration and allotment machinery the base subscription uses.
 */
readonly class AddOn
{
    public function __construct(
        public string $id,
        public string $priceId,
        public Money $price,
        public AddOnAlignment $alignment = AddOnAlignment::Aligned,
        public ?BillingCycle $cycle = null,
        public int $creditAllotment = 0,
    ) {
        if ($alignment === AddOnAlignment::Independent && $cycle === null) {
            throw new InvalidArgumentException(
                "Independent add-on [{$id}] requires its own billing cycle.",
            );
        }

        if ($creditAllotment < 0) {
            throw new InvalidArgumentException("Add-on [{$id}] credit allotment cannot be negative.");
        }
    }

    /** Whether this add-on runs on its own cycle rather than the base subscription's. */
    public function isIndependent(): bool
    {
        return $this->alignment === AddOnAlignment::Independent;
    }

    /**
     * The billing period this add-on charges and grants over at `$at`: the base
     * subscription's period when aligned, or its own cycle's period when independent.
     */
    public function periodFor(BillingPeriod $basePeriod, DateTimeImmutable $at): BillingPeriod
    {
        if ($this->cycle !== null && $this->isIndependent()) {
            return $this->cycle->periodContaining($at);
        }

        return $basePeriod;
    }

    /**
     * The prorated charge for adding this add-on mid-cycle at `$at`: its full price
     * prorated over the days still to run in its resolved period — the base period when
     * aligned, its own period when independent. Reuses the shared
     * {@see ProrationCalculator} so an add-on's money is priced exactly like a plan
     * change's.
     */
    public function proratedCharge(
        ProrationCalculator $calculator,
        BillingPeriod $basePeriod,
        DateTimeImmutable $at,
        GatewayRounding $rounding = GatewayRounding::HalfUp,
    ): Money {
        $period = $this->periodFor($basePeriod, $at);

        return $calculator->prorate(
            Money::zero($this->price->currency()),
            $this->price,
            $period,
            $at,
            $rounding,
        );
    }

    /**
     * The credit allotment granted when this add-on is added mid-cycle at `$at`.
     * `FullReset` grants the whole allotment; `Prorated` grants the remainder-safe share
     * of the days still to run in the resolved period — an aligned add-on prorates to
     * the base period (following the base allotment rules), an independent one to its
     * own.
     */
    public function grantedAllotment(
        BillingPeriod $basePeriod,
        DateTimeImmutable $at,
        CreditGrantMode $mode = CreditGrantMode::FullReset,
    ): int {
        if ($mode === CreditGrantMode::FullReset) {
            return $this->creditAllotment;
        }

        $period = $this->periodFor($basePeriod, $at);

        return ProratedAllotment::remainingShare(
            $this->creditAllotment,
            $period->remainingDays($at),
            $period->totalDays(),
        );
    }
}
