<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Exceptions;

use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\ValueObjects\Term;
use RuntimeException;

/**
 * Raised when a fixed-term purchase asks for a (term × kind) price point the catalog does
 * not offer at the pin date — e.g. a `P5Y`/`Redemption` price that was never published, or
 * one whose effective window does not cover the instant. Deny-by-default: the flow refuses
 * rather than inventing a price (ADR-0015).
 */
class TermPriceNotAvailable extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $productId,
        public readonly Term $term,
        public readonly PriceKind $kind,
    ) {
        parent::__construct($message);
    }

    public static function for(string $productId, Term $term, PriceKind $kind): self
    {
        return new self(
            message: "No {$kind->value} price for product [{$productId}] at term {$term->toIso8601()}.",
            productId: $productId,
            term: $term,
            kind: $kind,
        );
    }
}
