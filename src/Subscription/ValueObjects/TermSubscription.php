<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Subscription\Enums\TermSubscriptionStatus;
use Cbox\Billing\Subscription\TermLifecycle;
use DateTimeImmutable;

/**
 * A fixed-term (registrar-style) instance: one org holding one resource — a domain name,
 * a hosting term, a certificate — bought for a committed {@see Term} with a definite end
 * (ADR-0015). Many instances of the same product can coexist under one org (several
 * domains), each identified by its own {@see $instanceRef} and tracking its own term and
 * lifecycle state independently.
 *
 * Immutable: the registrar lifecycle transitions live in {@see TermLifecycle} and each
 * returns a new instance. The `$status` stored here is the last transitioned state;
 * {@see TermLifecycle::phaseAt()} computes the *effective* status at an instant (Active /
 * Grace / Redemption / Expired) from {@see $termEndsAt} and the product's windows.
 */
readonly class TermSubscription
{
    public function __construct(
        public string $id,
        public string $orgId,
        public string $productId,
        public string $instanceRef,
        public Term $term,
        public DateTimeImmutable $registeredAt,
        public DateTimeImmutable $termEndsAt,
        public bool $autoRenew,
        public TermSubscriptionStatus $status = TermSubscriptionStatus::Active,
    ) {}

    /**
     * Register a fresh Active instance for a term starting at `$registeredAt`. The term
     * end is the term added to the registration instant. Used on a `Register` (or
     * `Transfer`) purchase once its quote is issued.
     */
    public static function register(
        string $id,
        string $orgId,
        string $productId,
        string $instanceRef,
        Term $term,
        DateTimeImmutable $registeredAt,
        bool $autoRenew = false,
    ): self {
        return new self(
            $id,
            $orgId,
            $productId,
            $instanceRef,
            $term,
            $registeredAt,
            $term->addTo($registeredAt),
            $autoRenew,
            TermSubscriptionStatus::Active,
        );
    }

    public function isActive(): bool
    {
        return $this->status === TermSubscriptionStatus::Active;
    }
}
