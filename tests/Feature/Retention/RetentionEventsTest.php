<?php

declare(strict_types=1);

use Cbox\Billing\Retention\Enums\RetentionOutcome;
use Cbox\Billing\Retention\Events\RetentionResolved;
use Cbox\Billing\Retention\Events\SaveOfferAccepted;
use Cbox\Billing\Retention\Events\SubscriptionCancellationRequested;
use Cbox\Billing\Retention\RetentionRecorder;
use Cbox\Billing\Retention\ValueObjects\CancellationResponse;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\Subscription;
use Illuminate\Support\Facades\Event;

/**
 * The retention domain events fire at the recorder's real seam points, so a plugin/automation
 * listens rather than the engine calling it. Each test drives a REAL vector (a real
 * subscription, a real save-offer, a real response) and asserts the event fires with the exact
 * payload — and that a recorder with no dispatcher emits nothing (the inert default).
 */
function retentionSub(): Subscription
{
    $period = new BillingPeriod(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01'));

    return (new SubscriptionManager)->create('sub_1', 'org_1', 'prod_pro', 'price_pro', $period);
}

it('dispatches SubscriptionCancellationRequested with the subscription, account and response', function (): void {
    Event::fake();

    $sub = retentionSub();
    $response = new CancellationResponse('too_expensive', 'Found a cheaper option');

    $this->retentionRecorder()->cancellationRequested($sub, 'acme', $response);

    Event::assertDispatchedTimes(SubscriptionCancellationRequested::class, 1);
    Event::assertDispatched(SubscriptionCancellationRequested::class, function (SubscriptionCancellationRequested $e) use ($sub, $response): bool {
        return $e->subscription === $sub
            && $e->account === 'acme'
            && $e->response === $response
            && $e->response->reasonKey === 'too_expensive';
    });
});

it('dispatches SubscriptionCancellationRequested with a null response for a plain cancel', function (): void {
    Event::fake();

    $sub = retentionSub();

    $this->retentionRecorder()->cancellationRequested($sub, 'acme');

    Event::assertDispatched(SubscriptionCancellationRequested::class, function (SubscriptionCancellationRequested $e): bool {
        return $e->response === null;
    });
});

it('dispatches SaveOfferAccepted with the accepted offer and its typed params', function (): void {
    Event::fake();

    $sub = retentionSub();
    $offer = SaveOffer::discount('save_25', '25% off for 3 months', 25, 3);

    $this->retentionRecorder()->offerAccepted($sub, $offer);

    Event::assertDispatchedTimes(SaveOfferAccepted::class, 1);
    Event::assertDispatched(SaveOfferAccepted::class, function (SaveOfferAccepted $e) use ($sub, $offer): bool {
        return $e->subscription === $sub
            && $e->offer === $offer
            && $e->offer->discountPercent === 25
            && $e->offer->durationCycles === 3;
    });
});

it('dispatches RetentionResolved with the outcome and response', function (): void {
    Event::fake();

    $sub = retentionSub();
    $response = new CancellationResponse('missing_feature', null);

    $this->retentionRecorder()->resolved($sub, RetentionOutcome::SavedByOffer, $response);

    Event::assertDispatchedTimes(RetentionResolved::class, 1);
    Event::assertDispatched(RetentionResolved::class, function (RetentionResolved $e) use ($sub, $response): bool {
        return $e->subscription === $sub
            && $e->outcome === RetentionOutcome::SavedByOffer
            && $e->response === $response;
    });
});

it('resolves a plain cancel as Canceled with no response', function (): void {
    Event::fake();

    $this->retentionRecorder()->resolved(retentionSub(), RetentionOutcome::Canceled);

    Event::assertDispatched(RetentionResolved::class, function (RetentionResolved $e): bool {
        return $e->outcome === RetentionOutcome::Canceled && $e->response === null;
    });
});

it('emits nothing when the recorder has no dispatcher (the inert default)', function (): void {
    Event::fake();

    $recorder = new RetentionRecorder; // no dispatcher

    $recorder->cancellationRequested(retentionSub(), 'acme');
    $recorder->offerAccepted(retentionSub(), SaveOffer::freeMonth('one_free', 'One month free'));
    $recorder->resolved(retentionSub(), RetentionOutcome::Deferred);

    Event::assertNotDispatched(SubscriptionCancellationRequested::class);
    Event::assertNotDispatched(SaveOfferAccepted::class);
    Event::assertNotDispatched(RetentionResolved::class);
});
