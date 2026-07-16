<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Subscription\TermLifecycle;
use DateTimeImmutable;

/**
 * The post-expiry recovery windows of a fixed-term product (ADR-0015): after the term
 * ends an instance sits in a {@see Term} of grace, then a {@see Term} of redemption,
 * before it finally expires. Both are configurable per product line — a domain TLD's
 * grace/redemption differs from a certificate's — and default to the common
 * registrar-style 30-day grace + 30-day redemption.
 *
 * Immutable value object; {@see TermLifecycle} reads it to
 * decide the phase at a given instant.
 */
readonly class RegistrarWindows
{
    public function __construct(
        public Term $grace,
        public Term $redemption,
    ) {}

    /** The instant the grace window closes: term end + grace. */
    public function graceEndsAt(DateTimeImmutable $termEndsAt): DateTimeImmutable
    {
        return $this->grace->addTo($termEndsAt);
    }

    /** The instant the redemption window closes (the point of no return): term end + grace + redemption. */
    public function redemptionEndsAt(DateTimeImmutable $termEndsAt): DateTimeImmutable
    {
        return $this->redemption->addTo($this->graceEndsAt($termEndsAt));
    }
}
