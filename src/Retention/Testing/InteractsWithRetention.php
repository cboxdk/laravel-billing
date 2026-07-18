<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Testing;

use Cbox\Billing\Retention\RetentionRecorder;
use Cbox\Billing\Retention\ValueObjects\CancellationReason;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wire the retention seam in tests: a configurable {@see FakeCancellationSurvey} /
 * {@see FakeRetentionOffers} standing in for the app or plugin binding, and a
 * {@see RetentionRecorder} over a dispatcher to assert the retention events fire:
 *
 *     $survey = $this->fakeCancellationSurvey($reason);
 *     $offers = $this->fakeRetentionOffers($offer);
 *     $this->retentionRecorder()->cancellationRequested($sub, 'acme', $response);
 */
trait InteractsWithRetention
{
    protected function fakeCancellationSurvey(CancellationReason ...$reasons): FakeCancellationSurvey
    {
        return (new FakeCancellationSurvey)->offer(...$reasons);
    }

    protected function fakeRetentionOffers(SaveOffer ...$offers): FakeRetentionOffers
    {
        return (new FakeRetentionOffers)->present(...$offers);
    }

    protected function retentionRecorder(?Dispatcher $events = null): RetentionRecorder
    {
        return new RetentionRecorder($events ?? app(Dispatcher::class));
    }
}
