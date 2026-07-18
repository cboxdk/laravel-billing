<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention;

use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the retention seam contracts-first. Both the {@see CancellationSurvey} and the
 * {@see RetentionOffers} are bound with `bindIf`, so the first binder wins: the engine's Null
 * defaults keep the seam inert (no survey, no offers → a plain cancel), the deployable app
 * binds a basic default over them, and the private `cbox-billing-retention` plugin binds the
 * rich flow — each without forcing the other's presence.
 *
 * The {@see RetentionRecorder} is wired with the real event dispatcher so the host's cancel
 * path can emit the retention events; the dispatcher stays optional on the recorder itself, so
 * a direct instantiation without one is still valid and simply emits nothing.
 */
class RetentionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CancellationSurvey::class, NullCancellationSurvey::class);
        $this->app->bindIf(RetentionOffers::class, NullRetentionOffers::class);

        $this->app->singleton(RetentionRecorder::class, static fn (Application $app): RetentionRecorder => new RetentionRecorder(
            $app->make(Dispatcher::class),
        ));
    }
}
