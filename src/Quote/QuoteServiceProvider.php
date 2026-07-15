<?php

declare(strict_types=1);

namespace Cbox\Billing\Quote;

use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Tax\Contracts\TaxCalculator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the quote builder, which composes the tax engine into a confirmable
 * price breakdown.
 */
class QuoteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QuoteBuilder::class, static fn (Application $app): DefaultQuoteBuilder => new DefaultQuoteBuilder(
            $app->make(TaxCalculator::class),
        ));
    }
}
