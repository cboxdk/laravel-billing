<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement;

use Cbox\Billing\Entitlement\Audit\Console\AuditEntitlementsCommand;
use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAudit;
use Cbox\Billing\Entitlement\Audit\Contracts\EntitlementAuditSignals;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Audit\DefaultEntitlementAudit;
use Cbox\Billing\Entitlement\Audit\Signals\LoggingEntitlementAuditSignals;
use Cbox\Billing\Entitlement\Audit\Sources\InMemoryExpectedEntitlements;
use Cbox\Billing\Entitlement\Contracts\EntitlementProjector;
use Cbox\Billing\Entitlement\Contracts\EntitlementWriter;
use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Contracts\CacheInvalidator;
use Cbox\Billing\Entitlement\Rollout\Contracts\EntitlementRollout;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\DefaultEntitlementRollout;
use Cbox\Billing\Entitlement\Rollout\Invalidators\EventCacheInvalidator;
use Cbox\Billing\Entitlement\Rollout\Journal\InMemoryRolloutJournal;
use Cbox\Billing\Entitlement\Writers\NullEntitlementWriter;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Binds the entitlement projector and a no-op writer by default, plus the
 * per-meter policy resolver the metering enforcer reads through — entitlement
 * decides what each `(org, meter)` bucket is granted. The host binds a real writer
 * that adapts onto identity (cbox-id) and feeds meter-policy grants from plan state.
 *
 * Also wires the independent entitlement audit that detects the missing-row outage:
 * the audit reads the same {@see MeterPolicyResolver} rows, an outage-signal channel
 * that logs by default, and a host-supplied {@see ExpectedEntitlements} oracle that is
 * empty by default (deny-by-default: audit nothing until the host supplies targets).
 */
class EntitlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntitlementWriter::class, static fn (): NullEntitlementWriter => new NullEntitlementWriter);

        $this->app->singleton(EntitlementProjector::class, static fn (Application $app): DefaultEntitlementProjector => new DefaultEntitlementProjector(
            $app->make(EntitlementWriter::class),
        ));

        // Deny-by-default: an empty resolver grants nothing, so every metered
        // dimension is refused until the host grants a policy for it.
        $this->app->singleton(EntitlementMeterPolicyResolver::class, static fn (): EntitlementMeterPolicyResolver => new EntitlementMeterPolicyResolver);
        $this->app->bind(MeterPolicyResolver::class, EntitlementMeterPolicyResolver::class);

        // Audit: an empty expected-set oracle (nothing to check until the host wires
        // plan/catalog state), an outage-signal channel that logs, and the audit itself.
        $this->app->singleton(ExpectedEntitlements::class, static fn (): InMemoryExpectedEntitlements => new InMemoryExpectedEntitlements);

        $this->app->bind(EntitlementAuditSignals::class, static fn (Application $app): EntitlementAuditSignals => new LoggingEntitlementAuditSignals(
            $app->make(LoggerInterface::class),
        ));

        $this->app->bind(EntitlementAudit::class, static fn (Application $app): EntitlementAudit => new DefaultEntitlementAudit(
            $app->make(MeterPolicyResolver::class),
            $app->make(EntitlementAuditSignals::class),
        ));

        $this->registerRollout();
    }

    /**
     * Wire the plan-wide rollout: an in-memory journal over the same resolver the enforcer
     * reads (a host swaps a connection-backed journal for a durable audit trail), an
     * event-dispatching per-org cache invalidator, and the rollout service with the
     * configured chunk size.
     */
    private function registerRollout(): void
    {
        $this->app->singleton(RolloutJournal::class, static fn (Application $app): InMemoryRolloutJournal => new InMemoryRolloutJournal(
            $app->make(EntitlementMeterPolicyResolver::class),
        ));

        $this->app->bind(CacheInvalidator::class, static fn (Application $app): EventCacheInvalidator => new EventCacheInvalidator(
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(EntitlementRollout::class, static function (Application $app): DefaultEntitlementRollout {
            $chunkSize = $app->make(Config::class)->get('billing.entitlement.rollout.chunk_size', 500);

            return new DefaultEntitlementRollout(
                $app->make(RolloutJournal::class),
                $app->make(CacheInvalidator::class),
                $app->make(LoggerInterface::class),
                is_numeric($chunkSize) ? (int) $chunkSize : 500,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AuditEntitlementsCommand::class]);
        }
    }
}
