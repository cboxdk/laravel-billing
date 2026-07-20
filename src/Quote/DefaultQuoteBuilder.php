<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\Exceptions\InvalidQuoteLine;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Billing\Quote\ValueObjects\QuoteLine;
use Cbox\Billing\Quote\ValueObjects\QuoteTotals;
use Cbox\Billing\Quote\ValueObjects\TaxResolution;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Exceptions\JurisdictionNotResolved;
use Cbox\Tax\Exceptions\UnsupportedJurisdiction;
use Cbox\Tax\ValueObjects\TaxQuery;
use InvalidArgumentException;

/**
 * Builds a quote by running each line through the tax engine for the context's
 * seller/buyer, then applying available wallet credit. If the jurisdiction is not
 * resolved finely enough to tax (the tax engine refuses), the quote is returned as
 * *tax-pending* — net prices with an honest reason — never a wrong number.
 */
readonly class DefaultQuoteBuilder implements QuoteBuilder
{
    public function __construct(private TaxCalculator $tax) {}

    public function build(array $lines, QuoteContext $context): Quote
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A quote needs at least one line.');
        }

        $currency = $lines[0]->unitAmount->currency();

        // Fail fast on a mixed-currency quote BEFORE any tax/total is computed: summing
        // mismatched currencies would otherwise surface as a late brick/money mismatch
        // deep in totalling, long after the numbers looked plausible.
        foreach ($lines as $line) {
            $lineCurrency = $line->unitAmount->currency();
            if ($lineCurrency !== $currency) {
                throw InvalidQuoteLine::mixedCurrency($currency, $lineCurrency);
            }
        }

        try {
            return $this->resolved($lines, $context, $currency);
        } catch (JurisdictionNotResolved|UnsupportedJurisdiction $e) {
            return $this->pending($lines, $context, $currency, $e->getMessage());
        }
    }

    /**
     * @param  list<LineInput>  $lines
     */
    private function resolved(array $lines, QuoteContext $context, string $currency): Quote
    {
        $quoteLines = [];
        $net = Money::zero($currency);
        $tax = Money::zero($currency);
        $gross = Money::zero($currency);

        foreach ($lines as $line) {
            $lineAmount = $line->unitAmount->multipliedBy($line->quantity);

            $assessment = $this->tax->assess(new TaxQuery(
                amount: $lineAmount->toBrick(),
                pricing: $context->pricing,
                place: $context->place,
                customer: $context->customer,
                seller: $context->seller,
                category: $line->category,
                customerTaxIdValidated: $context->customerTaxIdValidated,
            ));

            $lineNet = Money::fromBrick($assessment->net);
            $lineTax = Money::fromBrick($assessment->tax);
            $lineGross = Money::fromBrick($assessment->gross);

            $quoteLines[] = new QuoteLine(
                $line->description,
                $line->quantity,
                $lineNet,
                $lineTax,
                $lineGross,
                $assessment->treatment,
                $assessment->rate !== null ? (string) $assessment->rate->percentage : null,
                $assessment->reason,
            );

            $net = $net->plus($lineNet);
            $tax = $tax->plus($lineTax);
            $gross = $gross->plus($lineGross);
        }

        return new Quote(
            $quoteLines,
            $this->totals($net, $tax, $gross, $context, $currency),
            $currency,
            $context->seller,
            $context->place,
            TaxResolution::resolved(),
        );
    }

    /**
     * @param  list<LineInput>  $lines
     */
    private function pending(array $lines, QuoteContext $context, string $currency, string $reason): Quote
    {
        $quoteLines = [];
        $net = Money::zero($currency);

        foreach ($lines as $line) {
            $lineNet = $line->unitAmount->multipliedBy($line->quantity);

            $quoteLines[] = new QuoteLine(
                $line->description,
                $line->quantity,
                $lineNet,
                Money::zero($currency),
                $lineNet,
                null,
                null,
                'Tax is calculated once the address / jurisdiction is resolved.',
            );

            $net = $net->plus($lineNet);
        }

        return new Quote(
            $quoteLines,
            $this->totals($net, Money::zero($currency), $net, $context, $currency),
            $currency,
            $context->seller,
            $context->place,
            TaxResolution::pending($reason),
        );
    }

    private function totals(Money $net, Money $tax, Money $gross, QuoteContext $context, string $currency): QuoteTotals
    {
        $credit = Money::zero($currency);
        $available = $context->creditAvailable;

        if ($available !== null && $available->isPositive() && $available->currency() === $currency) {
            // Apply at most the gross amount.
            $credit = $available->compareTo($gross) > 0 ? $gross : $available;
        }

        return new QuoteTotals($net, $tax, $gross, $credit, $gross->minus($credit));
    }
}
