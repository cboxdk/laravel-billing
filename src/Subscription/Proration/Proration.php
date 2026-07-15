<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Proration;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AnchorMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use DateTimeImmutable;

/**
 * The single source of truth for a plan change's money: its independently-rounded
 * lines, their net, when it takes effect, the anchor decision, and the rounding that
 * produced it. The previewer and the charger both read this exact object, so a
 * preview is a charge by construction. `net` is the sum of the already-rounded lines
 * (positive = charge now, negative = credit); a deferred change (a kept-anchor
 * downgrade) moves no money now and lands at the period end.
 */
readonly class Proration
{
    /**
     * @param  list<ProrationLine>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $net,
        public bool $deferred,
        public DateTimeImmutable $effectiveAt,
        public AnchorMode $anchor,
        public GatewayRounding $rounding,
        public string $currency,
    ) {}

    /** The money actually collected now: nothing when deferred or when the net is a credit. */
    public function dueNow(): Money
    {
        if ($this->deferred || ! $this->net->isPositive()) {
            return Money::zero($this->currency);
        }

        return $this->net;
    }

    /** True when the reset nets back more than it charges — the customer is owed a credit. */
    public function isCredit(): bool
    {
        return ! $this->deferred && $this->net->isNegative();
    }

    /**
     * A stable, comparable digest of what this proration will move — the exact thing a
     * preview promises and a charge commits. Two prorations with identical digests are
     * identical to the cent, line for line.
     *
     * @return array{net: int, deferred: bool, effectiveAt: string, anchor: string, rounding: string, lines: list<array{description: string, minor: int}>}
     */
    public function breakdown(): array
    {
        return [
            'net' => $this->net->minor(),
            'deferred' => $this->deferred,
            'effectiveAt' => $this->effectiveAt->format(DateTimeImmutable::ATOM),
            'anchor' => $this->anchor->value,
            'rounding' => $this->rounding->value,
            'lines' => array_map(
                static fn (ProrationLine $line): array => [
                    'description' => $line->description,
                    'minor' => $line->amount->minor(),
                ],
                $this->lines,
            ),
        ];
    }
}
