<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote\ValueObjects;

/**
 * Whether the tax on a quote is fully resolved, or still pending because the buyer
 * jurisdiction is not specified finely enough yet (e.g. a US address without a
 * state). A pending quote shows net prices and an honest "tax calculated at
 * checkout" reason rather than a wrong number.
 */
readonly class TaxResolution
{
    private function __construct(
        public bool $resolved,
        public ?string $reason,
    ) {}

    public static function resolved(): self
    {
        return new self(true, null);
    }

    public static function pending(string $reason): self
    {
        return new self(false, $reason);
    }
}
