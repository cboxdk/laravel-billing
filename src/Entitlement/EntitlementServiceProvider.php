<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement;

use Cbox\Billing\Entitlement\Contracts\EntitlementProjector;
use Cbox\Billing\Entitlement\Contracts\EntitlementWriter;
use Cbox\Billing\Entitlement\Writers\NullEntitlementWriter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the entitlement projector and a no-op writer by default. The host binds a
 * real writer that adapts onto identity (cbox-id).
 */
class EntitlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntitlementWriter::class, static fn (): NullEntitlementWriter => new NullEntitlementWriter);

        $this->app->singleton(EntitlementProjector::class, static fn (Application $app): DefaultEntitlementProjector => new DefaultEntitlementProjector(
            $app->make(EntitlementWriter::class),
        ));
    }
}
