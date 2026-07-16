<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription;

use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Subscription\Exceptions\TermPriceNotAvailable;
use Cbox\Tax\Enums\TaxCategory;
use DateTimeImmutable;

/**
 * Turns a fixed-term commercial action — register / renew / redeem / transfer-in — into
 * the {@see LineInput}(s) the existing {@see QuoteBuilder} taxes and applies credit to,
 * so a term purchase flows through the very same Quote → Invoice pipeline as everything
 * else (ADR-0015). The only term-specific step is *selecting the (term × kind) price* via
 * {@see Catalog::termPriceFor()}; tax, seller-of-record, credits, and dunning are unchanged.
 *
 * This helper does not price, tax, or move money itself — it composes the catalog lookup
 * with the shared QuoteBuilder, so there is no duplicated tax/credit logic.
 */
readonly class TermPurchase
{
    public function __construct(
        private Catalog $catalog,
        private QuoteBuilder $quotes,
    ) {}

    /**
     * The taxed, credit-aware {@see Quote} for buying `$quantity` of a fixed-term product
     * at a given (term × kind) price point. Register a domain, renew it, redeem it, or
     * transfer it in by passing the matching {@see PriceKind}.
     *
     * @throws TermPriceNotAvailable when the catalog offers no such price point at `$at`
     */
    public function quote(
        Product $product,
        Term $term,
        PriceKind $kind,
        int $quantity,
        QuoteContext $context,
        DateTimeImmutable $at,
        TaxCategory $category = TaxCategory::Standard,
        ?string $description = null,
    ): Quote {
        return $this->quotes->build(
            [$this->lineFor($product, $term, $kind, $quantity, $at, $category, $description)],
            $context,
        );
    }

    /**
     * The single {@see LineInput} for a fixed-term price point — the seam a caller can use
     * to compose a term line into a larger multi-line quote (e.g. a domain + its renewal).
     *
     * @throws TermPriceNotAvailable when the catalog offers no such price point at `$at`
     */
    public function lineFor(
        Product $product,
        Term $term,
        PriceKind $kind,
        int $quantity,
        DateTimeImmutable $at,
        TaxCategory $category = TaxCategory::Standard,
        ?string $description = null,
    ): LineInput {
        $price = $this->catalog->termPriceFor($product->id, $term, $kind, $at);

        if ($price === null) {
            throw TermPriceNotAvailable::for($product->id, $term, $kind);
        }

        return new LineInput(
            $description ?? sprintf('%s — %s (%s)', $product->name, $term->toIso8601(), $kind->value),
            $price->billableQuantity($quantity),
            $price->unitAmount,
            $category,
        );
    }
}
