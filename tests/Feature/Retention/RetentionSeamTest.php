<?php

declare(strict_types=1);

use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Cbox\Billing\Retention\Enums\SaveOfferType;
use Cbox\Billing\Retention\NullCancellationSurvey;
use Cbox\Billing\Retention\NullRetentionOffers;
use Cbox\Billing\Retention\ValueObjects\CancellationReason;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;

/**
 * The retention seam: the survey + offers contracts. With no plugin the engine binds the Null
 * defaults, which return empty — a plain cancel works and nothing is surfaced. A fake (the app
 * or plugin binding) returns the configured reasons + save-offers, and each offer carries the
 * right typed params for its type.
 */

// --- The inert default: no plugin, empty survey + offers, plain cancel still works ---

it('binds the Null survey + offers by default so the seam is inert', function (): void {
    expect(app(CancellationSurvey::class))->toBeInstanceOf(NullCancellationSurvey::class);
    expect(app(RetentionOffers::class))->toBeInstanceOf(NullRetentionOffers::class);
});

it('the Null survey offers no reasons and the Null offers present none', function (): void {
    $survey = new NullCancellationSurvey;
    $offers = new NullRetentionOffers;

    expect($survey->reasonsFor('acme', 'sub_1'))->toBe([]);
    expect($offers->offersFor('acme', 'sub_1'))->toBe([]);
});

it('a plain cancel proceeds unchanged with no survey and no offers', function (): void {
    // With the inert defaults, the survey/offers surface nothing, so the host proceeds straight
    // to the engine's unchanged cancel transition.
    expect(app(CancellationSurvey::class)->reasonsFor('acme', 'sub_1'))->toBe([]);
    expect(app(RetentionOffers::class)->offersFor('acme', 'sub_1'))->toBe([]);

    $manager = new SubscriptionManager;
    $period = new BillingPeriod(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'));
    $sub = $manager->create('sub_1', 'org_1', 'prod_pro', 'price_pro', $period);

    $canceled = $manager->cancelNow($sub);

    expect($canceled->isCanceled())->toBeTrue();
});

// --- A configured survey + offers (the app/plugin binding) ---

it('a fake survey returns the configured reasons', function (): void {
    $survey = $this->fakeCancellationSurvey(
        new CancellationReason('too_expensive', 'Too expensive'),
        new CancellationReason('missing_feature', 'Missing a feature'),
        new CancellationReason('other', 'Something else', requiresComment: true),
    );

    $reasons = $survey->reasonsFor('acme', 'sub_1');

    expect($reasons)->toHaveCount(3);
    expect($reasons[0]->key)->toBe('too_expensive');
    expect($reasons[0]->requiresComment)->toBeFalse();
    expect($reasons[2]->key)->toBe('other');
    expect($reasons[2]->requiresComment)->toBeTrue();
});

it('a fake offers source returns a discount, free-month, pause and downgrade offer with typed params', function (): void {
    $offers = $this->fakeRetentionOffers(
        SaveOffer::discount('save_25', '25% off for 3 months', 25, 3),
        SaveOffer::freeMonth('one_free', 'One month free'),
        SaveOffer::pause('pause_2', 'Pause for 2 cycles', 2),
        SaveOffer::downgrade('to_basic', 'Move to Basic', 'prod_basic', 'price_basic'),
        SaveOffer::custom('book_call', 'Talk to us'),
    );

    $presented = $offers->offersFor('acme', 'sub_1');

    expect($presented)->toHaveCount(5);

    $discount = $presented[0];
    expect($discount->type)->toBe(SaveOfferType::Discount);
    expect($discount->discountPercent)->toBe(25);
    expect($discount->durationCycles)->toBe(3);

    $free = $presented[1];
    expect($free->type)->toBe(SaveOfferType::FreeMonth);
    expect($free->freeMonths)->toBe(1);

    $pause = $presented[2];
    expect($pause->type)->toBe(SaveOfferType::Pause);
    expect($pause->pauseCycles)->toBe(2);

    $downgrade = $presented[3];
    expect($downgrade->type)->toBe(SaveOfferType::Downgrade);
    expect($downgrade->targetProductId)->toBe('prod_basic');
    expect($downgrade->targetPriceId)->toBe('price_basic');

    expect($presented[4]->type)->toBe(SaveOfferType::Custom);
});

// --- The VO invariants: an offer can never carry the wrong params for its type ---

it('rejects a discount offer outside 0-100 percent', function (): void {
    expect(fn () => SaveOffer::discount('bad', 'Bad', 120, 3))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a free-month offer with no free months', function (): void {
    expect(fn () => SaveOffer::freeMonth('bad', 'Bad', 0))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a pause offer with no duration', function (): void {
    expect(fn () => SaveOffer::pause('bad', 'Bad', 0))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a downgrade offer with no target', function (): void {
    expect(fn () => SaveOffer::downgrade('bad', 'Bad', '', 'price_basic'))
        ->toThrow(InvalidArgumentException::class);
});
