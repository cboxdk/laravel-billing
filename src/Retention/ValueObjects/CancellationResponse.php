<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\ValueObjects;

/**
 * What a subscriber answered on the cancel survey: the {@see CancellationReason} key they
 * picked (if any) and an optional free-text comment. Both are nullable — a plain cancel
 * with no configured survey carries no response at all, which is the inert default.
 */
readonly class CancellationResponse
{
    public function __construct(
        public ?string $reasonKey = null,
        public ?string $comment = null,
    ) {}

    public function hasComment(): bool
    {
        return $this->comment !== null && $this->comment !== '';
    }
}
