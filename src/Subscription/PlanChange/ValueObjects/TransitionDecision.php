<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\PlanChange\ValueObjects;

use Cbox\Billing\Subscription\Contracts\TransitionPolicy;

/**
 * The verdict of a {@see TransitionPolicy} on a
 * proposed plan change — a two-case sum type, `Allowed` | `Disallowed(reason)`:
 *
 *  - **Allowed** carries the optional `guidance` and the `carryOver` flag drawn from
 *    the edge that permitted it, so the change flow can surface guidance and apply the
 *    right credit consequence (ADR-0011) without re-deriving it.
 *  - **Disallowed** carries a human-readable `reason` the caller surfaces instead of
 *    silently prorating (ADR-0010).
 */
readonly class TransitionDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason,
        public ?string $guidance,
        public bool $carryOver,
    ) {}

    public static function allowed(?string $guidance = null, bool $carryOver = false): self
    {
        return new self(allowed: true, reason: null, guidance: $guidance, carryOver: $carryOver);
    }

    public static function disallowed(string $reason): self
    {
        return new self(allowed: false, reason: $reason, guidance: null, carryOver: false);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}
