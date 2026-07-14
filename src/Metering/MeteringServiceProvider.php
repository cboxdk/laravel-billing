<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering;

use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Stores\CacheLocalStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the metering module. Only the pieces with a real production default are
 * bound here: the node-local counter store (Laravel cache). The `Enforcement`
 * hot path, the remote `AllowanceLeaseSource`, and a durable `UsageBuffer` are
 * supplied by the app-side SDK (`laravel-billing-client`) — binding an in-memory
 * buffer as a default would silently lose usage, so we don't.
 */
class MeteringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LocalStore::class, function (Application $app): LocalStore {
            $prefix = $app->make('config')->get('billing.metering.lease.prefix', 'cbox-billing:lease:');

            return new CacheLocalStore(
                $app->make('cache')->store(),
                is_string($prefix) ? $prefix : 'cbox-billing:lease:',
            );
        });
    }
}
