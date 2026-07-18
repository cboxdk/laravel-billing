<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Retirement;

use Cbox\Billing\Subscription\Retirement\Enums\RetirementOutcome;
use DateTimeImmutable;

/**
 * The verdict of a {@see PlanRetirementResolver} on a subscription's upcoming renewal
 * against its plan's retirement (ADR-0016) — a tagged sum type over
 * {@see RetirementOutcome}:
 *
 *  - {@see notRetiring()}
 *  - {@see retiringChooseBy()}     carries the renewal-due deadline + the default (if any).
 *  - {@see resolvedToSuccessor()}  carries the chosen successor plan id.
 *  - {@see resolvedToCancel()}
 *  - {@see resolvedToDefault()}    carries the configured default plan id.
 *  - {@see unresolved()}
 *
 * Cancellation is a first-class, equal choice — not a fallback. The `Unresolved` case is
 * deny-by-default: a retired plan with no choice and no default never silently renews.
 */
readonly class RetirementResolution
{
    private function __construct(
        public RetirementOutcome $outcome,
        public ?string $successorPlanId = null,
        public ?DateTimeImmutable $renewalDueDate = null,
        public ?string $defaultSuccessorPlanId = null,
    ) {}

    public static function notRetiring(): self
    {
        return new self(RetirementOutcome::NotRetiring);
    }

    public static function retiringChooseBy(DateTimeImmutable $renewalDueDate, ?string $defaultSuccessorPlanId = null): self
    {
        return new self(
            RetirementOutcome::RetiringChooseBy,
            renewalDueDate: $renewalDueDate,
            defaultSuccessorPlanId: $defaultSuccessorPlanId,
        );
    }

    public static function resolvedToSuccessor(string $planId): self
    {
        return new self(RetirementOutcome::ResolvedToSuccessor, successorPlanId: $planId);
    }

    public static function resolvedToCancel(): self
    {
        return new self(RetirementOutcome::ResolvedToCancel);
    }

    public static function resolvedToDefault(string $planId): self
    {
        return new self(RetirementOutcome::ResolvedToDefault, successorPlanId: $planId);
    }

    public static function unresolved(): self
    {
        return new self(RetirementOutcome::UnresolvedRetirement);
    }

    public function isNotRetiring(): bool
    {
        return $this->outcome === RetirementOutcome::NotRetiring;
    }

    public function isUnresolved(): bool
    {
        return $this->outcome === RetirementOutcome::UnresolvedRetirement;
    }

    /** Whether enacting this resolution migrates the subscription onto a successor plan. */
    public function migratesToSuccessor(): bool
    {
        return $this->outcome === RetirementOutcome::ResolvedToSuccessor
            || $this->outcome === RetirementOutcome::ResolvedToDefault;
    }

    /** Whether enacting this resolution cancels the subscription at the renewal. */
    public function cancels(): bool
    {
        return $this->outcome === RetirementOutcome::ResolvedToCancel;
    }
}
