<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering;

use Cbox\Billing\Metering\Contracts\EnforcementSignals;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Contracts\MeterIngest;
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;
use Cbox\Billing\Metering\Signals\LoggingEnforcementSignals;
use Cbox\Billing\Metering\Storage\DatabaseEventLog;
use Cbox\Billing\Metering\Storage\InMemoryEventLog;
use Cbox\Billing\Metering\Stores\CacheLocalStore;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

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

        // ADR-0004: the default operator signal channel logs fail-open/fail-closed
        // infra events. The `Enforcement` hot path itself is supplied by the app-side
        // SDK, but it resolves this signal channel (and the infra policy below) from
        // the container so both are configured centrally.
        $this->app->bind(EnforcementSignals::class, static fn (Application $app): EnforcementSignals => new LoggingEnforcementSignals(
            $app->make(LoggerInterface::class),
        ));

        // The infra failure policy that resolves an indeterminate decision: fail-open
        // (`allow`, the availability-preserving default) or fail-closed (`deny`).
        $this->app->bind(InfraFailurePolicy::class, static fn (Application $app): InfraFailurePolicy => InfraFailurePolicy::fromConfig(
            $app->make(Config::class)->get('billing.metering.enforcement.infra_failure'),
        ));
    }
}
