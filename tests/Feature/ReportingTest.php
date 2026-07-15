<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ChurnCalculator;
use Cbox\Billing\Reporting\MrrCalculator;

it('summarizes MRR and ARR per currency', function () {
    $report = (new MrrCalculator)->summarize([
        Money::ofMinor(5000, 'EUR'),   // 50.00
        Money::ofMinor(10000, 'EUR'),  // 100.00
        Money::ofMinor(20000, 'GBP'),  // 200.00
    ]);

    $eur = $report->lineFor('EUR');
    $gbp = $report->lineFor('GBP');

    expect($eur->mrr->minor())->toBe(15000)
        ->and($eur->arr->minor())->toBe(180000)   // x12
        ->and($eur->subscriptions)->toBe(2)
        ->and($gbp->mrr->minor())->toBe(20000)
        ->and($gbp->arr->minor())->toBe(240000);
});

it('computes churn rate and guards an empty start', function () {
    $churn = new ChurnCalculator;

    expect($churn->rate(100, 5))->toBe(0.05)
        ->and($churn->rate(0, 0))->toBe(0.0);
});
