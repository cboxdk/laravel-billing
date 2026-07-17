<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\CohortRetention;
use Cbox\Billing\Reporting\MrrCalculator;
use Cbox\Billing\Reporting\MrrMovement;
use Cbox\Billing\Reporting\RetentionCalculator;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionMovement;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionMrr;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionPeriodMrr;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;

it('decomposes MRR movement into new, expansion, contraction, churn and reactivation per currency', function () {
    $eur = fn (int $minor) => Money::ofMinor($minor, 'EUR');
    $gbp = fn (int $minor) => Money::ofMinor($minor, 'GBP');

    $report = (new MrrMovement)->waterfall([
        new SubscriptionMovement('A', $eur(10000), $eur(10000)),            // unchanged
        new SubscriptionMovement('B', $eur(5000), $eur(8000)),             // expansion +3000
        new SubscriptionMovement('C', $eur(20000), $eur(12000)),           // contraction -8000
        new SubscriptionMovement('D', $eur(15000), $eur(0)),               // churn 15000
        new SubscriptionMovement('E', $eur(0), $eur(6000)),                // new 6000
        new SubscriptionMovement('F', $eur(0), $eur(4000), returning: true), // reactivation 4000
        new SubscriptionMovement('G', $gbp(0), $gbp(9000)),                // GBP new 9000
    ]);

    $w = $report->waterfallFor('EUR');

    expect($w)->not->toBeNull()
        ->and($w->startMrr->minor())->toBe(50000)
        ->and($w->endMrr->minor())->toBe(40000)
        ->and($w->new->minor())->toBe(6000)
        ->and($w->expansion->minor())->toBe(3000)
        ->and($w->contraction->minor())->toBe(8000)
        ->and($w->churn->minor())->toBe(15000)
        ->and($w->reactivation->minor())->toBe(4000)
        ->and($w->netChange()->minor())->toBe(-10000)
        ->and($w->reconciles())->toBeTrue();

    // start + new + expansion − contraction − churn + reactivation = end, exactly.
    expect(50000 + 6000 + 3000 - 8000 - 15000 + 4000)->toBe(40000);

    $g = $report->waterfallFor('GBP');

    expect($g->startMrr->minor())->toBe(0)
        ->and($g->endMrr->minor())->toBe(9000)
        ->and($g->new->minor())->toBe(9000)
        ->and($g->reactivation->minor())->toBe(0)
        ->and($g->reconciles())->toBeTrue();

    // Deterministic ordering: EUR before GBP.
    expect(array_map(fn ($w) => $w->currency, $report->waterfalls))->toBe(['EUR', 'GBP']);
});

it('derives the ARR waterfall as the MRR waterfall × 12, preserving the identity', function () {
    $eur = fn (int $minor) => Money::ofMinor($minor, 'EUR');

    $report = (new MrrMovement)->waterfall([
        new SubscriptionMovement('B', $eur(5000), $eur(8000)),
        new SubscriptionMovement('C', $eur(20000), $eur(12000)),
        new SubscriptionMovement('D', $eur(15000), $eur(0)),
        new SubscriptionMovement('E', $eur(0), $eur(6000)),
        new SubscriptionMovement('F', $eur(0), $eur(4000), returning: true),
        new SubscriptionMovement('A', $eur(10000), $eur(10000)),
    ]);

    $arr = $report->waterfallFor('EUR')->toArr();

    expect($arr->startArr->minor())->toBe(600000)
        ->and($arr->endArr->minor())->toBe(480000)
        ->and($arr->new->minor())->toBe(72000)
        ->and($arr->expansion->minor())->toBe(36000)
        ->and($arr->contraction->minor())->toBe(96000)
        ->and($arr->churn->minor())->toBe(180000)
        ->and($arr->reactivation->minor())->toBe(48000)
        ->and($arr->netChange()->minor())->toBe(-120000)
        ->and($arr->reconciles())->toBeTrue();
});

it('computes NRR and GRR exactly from minor-unit sums', function () {
    $eur = fn (int $minor) => Money::ofMinor($minor, 'EUR');

    // start 100000, +20000 expansion, −5000 contraction, −10000 churn → NRR 105%, GRR 85%.
    $rates = (new RetentionCalculator)->forCohort($eur(100000), $eur(20000), $eur(5000), $eur(10000));

    expect($rates->currency)->toBe('EUR')
        ->and($rates->nrr->numerator)->toBe(105000)
        ->and($rates->nrr->denominator)->toBe(100000)
        ->and($rates->nrr->basisPoints())->toBe(10500)   // 105.00%
        ->and($rates->grr->numerator)->toBe(85000)
        ->and($rates->grr->basisPoints())->toBe(8500);    // 85.00%
});

it('rounds retention basis points half away from zero without float drift', function () {
    // 1/3 = 3333.33.. bps → rounds to 3333; 2/3 = 6666.66.. → rounds to 6667.
    $eur = fn (int $minor) => Money::ofMinor($minor, 'EUR');

    $third = (new RetentionCalculator)->forCohort($eur(3), $eur(0), $eur(2), $eur(0));
    expect($third->nrr->basisPoints())->toBe(3333)
        ->and($third->grr->basisPoints())->toBe(3333);

    $twoThirds = (new RetentionCalculator)->forCohort($eur(3), $eur(0), $eur(1), $eur(0));
    expect($twoThirds->nrr->basisPoints())->toBe(6667);
});

it('derives retention from an MRR waterfall, excluding new and reactivation', function () {
    $eur = fn (int $minor) => Money::ofMinor($minor, 'EUR');

    $report = (new MrrMovement)->waterfall([
        new SubscriptionMovement('B', $eur(5000), $eur(8000)),   // expansion 3000
        new SubscriptionMovement('C', $eur(20000), $eur(12000)), // contraction 8000
        new SubscriptionMovement('D', $eur(15000), $eur(0)),     // churn 15000
        new SubscriptionMovement('A', $eur(10000), $eur(10000)),
        new SubscriptionMovement('E', $eur(0), $eur(9999)),      // new — excluded from retention
    ]);

    $rates = (new RetentionCalculator)->fromWaterfall($report->waterfallFor('EUR'));

    // start 50000, +3000 exp, −8000 contr, −15000 churn → NRR 30000/50000, GRR 27000/50000.
    expect($rates->nrr->numerator)->toBe(30000)
        ->and($rates->nrr->denominator)->toBe(50000)
        ->and($rates->nrr->basisPoints())->toBe(6000)
        ->and($rates->grr->numerator)->toBe(27000)
        ->and($rates->grr->basisPoints())->toBe(5400);
});

it('undefined retention (no starting cohort) reports zero rather than dividing by zero', function () {
    $rates = (new RetentionCalculator)->forCohort(
        Money::zero('EUR'),
        Money::zero('EUR'),
        Money::zero('EUR'),
        Money::zero('EUR'),
    );

    expect($rates->nrr->isDefined())->toBeFalse()
        ->and($rates->nrr->basisPoints())->toBe(0)
        ->and($rates->grr->basisPoints())->toBe(0);
});

it('builds a cohort × age retention matrix of retained count and MRR', function () {
    $eur = fn (int $minor) => Money::ofMinor($minor, 'EUR');
    $periods = ['2026-01', '2026-02', '2026-03'];

    $matrix = (new CohortRetention)->matrix($periods, [
        // Cohort 2026-01
        new SubscriptionPeriodMrr('s1', '2026-01', [$eur(10000), $eur(10000), $eur(0)]),      // churns in P3
        new SubscriptionPeriodMrr('s2', '2026-01', [$eur(20000), $eur(20000), $eur(20000)]),  // survives
        // Cohort 2026-02
        new SubscriptionPeriodMrr('s3', '2026-02', [$eur(0), $eur(15000), $eur(15000)]),      // survives
        new SubscriptionPeriodMrr('s4', '2026-02', [$eur(0), $eur(5000), $eur(0)]),           // churns in P3
    ]);

    expect($matrix->periods)->toBe($periods)
        ->and(array_map(fn ($r) => $r->cohort, $matrix->rows))->toBe(['2026-01', '2026-02']);

    $jan = $matrix->rowFor('2026-01');
    expect($jan->initialCount)->toBe(2)
        ->and($jan->initialMrr->minor())->toBe(30000)
        ->and($jan->cellAtAge(0)->retainedCount)->toBe(2)
        ->and($jan->cellAtAge(0)->retainedMrr->minor())->toBe(30000)
        ->and($jan->cellAtAge(1)->retainedCount)->toBe(2)
        ->and($jan->cellAtAge(1)->retainedMrr->minor())->toBe(30000)
        ->and($jan->cellAtAge(2)->retainedCount)->toBe(1)       // s1 churned
        ->and($jan->cellAtAge(2)->retainedMrr->minor())->toBe(20000)
        ->and($jan->cellAtAge(2)->periodIndex)->toBe(2);

    $feb = $matrix->rowFor('2026-02');
    expect($feb->initialCount)->toBe(2)
        ->and($feb->initialMrr->minor())->toBe(20000)
        ->and($feb->cellAtAge(0)->periodIndex)->toBe(1)         // cohort starts at period index 1
        ->and($feb->cellAtAge(0)->retainedCount)->toBe(2)
        ->and($feb->cellAtAge(1)->retainedCount)->toBe(1)       // s4 churned
        ->and($feb->cellAtAge(1)->retainedMrr->minor())->toBe(15000)
        ->and($feb->cellAtAge(1)->periodIndex)->toBe(2)
        ->and($feb->cellAtAge(2))->toBeNull();                  // no period beyond the window
});

it('applies the state→MRR policy: Paused and Canceled contribute 0, NonRenewing still counts', function () {
    $eur = fn (int $minor) => Money::ofMinor($minor, 'EUR');

    $report = (new MrrCalculator)->summarizeSubscriptions([
        new SubscriptionMrr(SubscriptionStatus::Paused, $eur(10000)),      // 0
        new SubscriptionMrr(SubscriptionStatus::Canceled, $eur(20000)),    // 0
        new SubscriptionMrr(SubscriptionStatus::NonRenewing, $eur(5000)),  // counts
        new SubscriptionMrr(SubscriptionStatus::Active, $eur(8000)),       // counts
        new SubscriptionMrr(SubscriptionStatus::Trialing, $eur(3000)),     // 0
        new SubscriptionMrr(SubscriptionStatus::PastDue, $eur(4000)),      // counts
    ]);

    $line = $report->lineFor('EUR');

    expect($line->mrr->minor())->toBe(17000)      // 5000 + 8000 + 4000
        ->and($line->arr->minor())->toBe(204000)  // × 12
        ->and($line->subscriptions)->toBe(3);
});

it('documents which lifecycle states contribute to MRR', function () {
    $calc = new MrrCalculator;

    expect($calc->contributes(SubscriptionStatus::Active))->toBeTrue()
        ->and($calc->contributes(SubscriptionStatus::PastDue))->toBeTrue()
        ->and($calc->contributes(SubscriptionStatus::NonRenewing))->toBeTrue()
        ->and($calc->contributes(SubscriptionStatus::Trialing))->toBeFalse()
        ->and($calc->contributes(SubscriptionStatus::Paused))->toBeFalse()
        ->and($calc->contributes(SubscriptionStatus::Canceled))->toBeFalse();
});

it('resolves the reporting calculators from the container', function () {
    expect($this->app->make(MrrMovement::class))->toBeInstanceOf(MrrMovement::class)
        ->and($this->app->make(RetentionCalculator::class))->toBeInstanceOf(RetentionCalculator::class)
        ->and($this->app->make(CohortRetention::class))->toBeInstanceOf(CohortRetention::class);
});
