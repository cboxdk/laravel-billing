<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering;

use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Contracts\MeterIngest;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Metering\Storage\InMemoryEventLog;
use Cbox\Billing\Metering\Stores\CacheLocalStore;
use Illuminate\Contracts\Config\Repository as Config;
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

        // The immutable event log (metering source of truth). Relational by config
        // for small deployments; a ClickHouse adapter binds here for scale.
        $this->app->singleton(EventLog::class, static function (Application $app): EventLog {
            $driver = $app->make(Config::class)->get('billing.metering.event_log', 'memory');

            if ($driver === 'database') {
                return new DatabaseEventLog($app->make('db')->connection());
            }

            return new InMemoryEventLog;
        });

        $this->app->singleton(MeterIngest::class, static fn (Application $app): MeterIngest => new DefaultMeterIngest(
            $app->make(EventLog::class),
        ));
    }
}
