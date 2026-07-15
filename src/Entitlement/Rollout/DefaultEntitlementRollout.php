<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout;

use Cbox\Billing\Entitlement\Rollout\Contracts\CacheInvalidator;
use Cbox\Billing\Entitlement\Rollout\Contracts\EntitlementRollout;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\Enums\RolloutPath;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\PlanEntitlementChange;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutApplication;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutChunk;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutReport;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutTarget;
use Psr\Log\LoggerInterface;

/**
 * The default rollout: split the cohort, route each half to the path that fits it.
 *
 * WHY TWO PATHS
 * -------------
 * A per-org cache-bust event is cheap once and catastrophic 100k times over — fired for
 * every org on a plan it stampedes the hot-path cache the metering enforcer reads through.
 * Yet the few orgs with tailored (override) entitlements genuinely need their bust NOW, or
 * they would serve stale entitlements until the TTL lapses. The two needs are opposite, so
 * they get two paths and the cohort is split between them:
 *
 *  - {@see rolloutBulk()} — no-override orgs, applied in atomic chunks with NO bust:
 *    invalidation rides the cache TTL. This is what keeps the 100k-org tail from storming
 *    the cache. One summary is logged for the whole cohort, never one event per org.
 *  - {@see rolloutOverrides()} — override orgs, each resolved (plan baseline overlaid with
 *    its overrides), written, then busted IMMEDIATELY and individually.
 *
 * The whole thing is idempotent: the journal upserts by `(rolloutId, org)`, so re-running
 * the same {@see PlanEntitlementChange} after a partial failure double-writes nothing.
 */
readonly class DefaultEntitlementRollout implements EntitlementRollout
{
    public function __construct(
        private RolloutJournal $journal,
        private CacheInvalidator $invalidator,
        private LoggerInterface $logger,
        private int $chunkSize = 500,
    ) {}

    public function apply(PlanEntitlementChange $change, iterable $cohort): RolloutReport
    {
        $bulk = [];
        $override = [];

        foreach ($cohort as $target) {
            if ($target->hasOverride()) {
                $override[] = $target;

                continue;
            }

            $bulk[] = $target;
        }

        $chunks = $this->rolloutBulk($change, $bulk);
        $busts = $this->rolloutOverrides($change, $override);

        // ONE bulk summary, not N per-org events — the whole point of the split.
        $this->logger->info(
            'Entitlement rollout applied: bulk cohort updated event-suppressed (TTL invalidation); overrides busted immediately.',
            [
                'rollout_id' => $change->id,
                'plan' => $change->plan,
                'bulk_orgs' => count($bulk),
                'override_orgs' => count($override),
                'chunks' => $chunks,
                'busts_fired' => $busts,
                'chunk_size' => $this->effectiveChunkSize(),
            ],
        );

        return new RolloutReport(
            $change->id,
            $change->plan,
            count($bulk),
            count($override),
            $chunks,
            $busts,
        );
    }

    /**
     * The event-suppressed path: apply the plan change verbatim to every no-override org,
     * one atomic chunk at a time, and emit NO per-org cache-bust — the TTL invalidates.
     *
     * @param  list<RolloutTarget>  $bulk
     * @return int how many chunk-transactions were committed
     */
    private function rolloutBulk(PlanEntitlementChange $change, array $bulk): int
    {
        if ($bulk === []) {
            return 0;
        }

        $chunks = 0;

        foreach (array_chunk($bulk, $this->effectiveChunkSize()) as $group) {
            $applications = array_map(
                static fn (RolloutTarget $target): RolloutApplication => new RolloutApplication(
                    $target->org,
                    $change->grants,
                    RolloutPath::Bulk,
                ),
                $group,
            );

            $this->journal->commit(new RolloutChunk($change->id, $change->plan, $applications));
            $chunks++;
        }

        return $chunks;
    }

    /**
     * The per-org audited path: for each override org resolve the effective grants (plan
     * baseline overlaid with the org's overrides), write them in their own atomic chunk,
     * then fire the immediate cache-bust so the tailored entitlements take effect now.
     *
     * @param  list<RolloutTarget>  $override
     * @return int how many per-org busts were fired
     */
    private function rolloutOverrides(PlanEntitlementChange $change, array $override): int
    {
        $busts = 0;

        foreach ($override as $target) {
            // Override wins over the plan baseline for the same meter.
            $grants = [...$change->grants, ...$target->overrides];

            $this->journal->commit(new RolloutChunk($change->id, $change->plan, [
                new RolloutApplication($target->org, $grants, RolloutPath::Override),
            ]));

            $this->invalidator->invalidate($target->org);
            $busts++;
        }

        return $busts;
    }

    /**
     * @return int<1, max>
     */
    private function effectiveChunkSize(): int
    {
        return max(1, $this->chunkSize);
    }
}
