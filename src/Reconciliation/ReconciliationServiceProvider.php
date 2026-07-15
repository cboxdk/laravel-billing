<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation;

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Reconciliation\Console\ReconcileUsageCommand;
use Cbox\Billing\Reconciliation\Contracts\CheckpointStore;
use Cbox\Billing\Reconciliation\Contracts\Reconciler;
use Cbox\Billing\Reconciliation\Storage\DatabaseCheckpointStore;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires convergent reconciliation (ADR-0003). The checkpoint store defaults to the
 * zero-config in-memory store and swaps to the durable one by config; the reconciler
 * resolves the durable {@see EventLog} and {@see Ledger} it converges between, and
 * reads the ingest-lag / window / denomination knobs from config.
 */
class ReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CheckpointStore::class, static function (Application $app): CheckpointStore {
            $driver = $app->make(Config::class)->get('billing.reconciliation.checkpoint_store', 'memory');

            if ($driver === 'database') {
                return new DatabaseCheckpointStore($app->make('db')->connection());
            }

            return new InMemoryCheckpointStore;
        });

        $this->app->singleton(Reconciler::class, static function (Application $app): Reconciler {
            $config = $app->make(Config::class);

            return new DefaultReconciler(
                checkpoints: $app->make(CheckpointStore::class),
                eventLog: $app->make(EventLog::class),
                ledger: $app->make(Ledger::class),
                ingestLagMs: self::intConfig($config, 'billing.reconciliation.ingest_lag_seconds', 60) * 1_000,
                windowMs: self::intConfig($config, 'billing.reconciliation.window_days', 32) * 86_400 * 1_000,
                currency: self::stringConfig($config, 'billing.reconciliation.currency', 'EUR'),
                clock: static fn (): int => (int) (microtime(true) * 1_000),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileUsageCommand::class]);
        }
    }

    private static function intConfig(Config $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function stringConfig(Config $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
