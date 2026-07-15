<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Testing;

use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\DefaultEntitlementRollout;
use Psr\Log\NullLogger;

/**
 * Wire up an entitlement rollout in tests:
 *
 *     $resolver = new EntitlementMeterPolicyResolver;        // the entitlement "rows"
 *     $journal  = $this->rolloutJournal($resolver);          // FakeRolloutJournal over them
 *     $busts    = $this->recordingInvalidator();             // RecordingCacheInvalidator
 *     $rollout  = $this->makeRollout($journal, $busts, chunkSize: 2);
 *
 *     $report = $rollout->apply($change, [
 *         RolloutTarget::bulk('org_a'),
 *         RolloutTarget::withOverrides('org_z', ['seats' => MeterPolicy::unlimited()]),
 *     ]);
 *
 *     expect($busts->bustCount())->toBe(1);                  // only the override org
 *     expect($report->bulkOrgs)->toBe(1);
 *
 * The journal writes into the same {@see EntitlementMeterPolicyResolver} the enforcer
 * reads, so a rollout write is visible to resolution exactly as in production.
 */
trait InteractsWithEntitlementRollout
{
    private ?RecordingCacheInvalidator $rolloutInvalidator = null;

    private ?FakeRolloutJournal $rolloutJournalFake = null;

    protected function recordingInvalidator(): RecordingCacheInvalidator
    {
        return $this->rolloutInvalidator ??= new RecordingCacheInvalidator;
    }

    protected function rolloutJournal(?EntitlementMeterPolicyResolver $resolver = null): FakeRolloutJournal
    {
        return $this->rolloutJournalFake ??= new FakeRolloutJournal($resolver ?? new EntitlementMeterPolicyResolver);
    }

    protected function makeRollout(
        ?FakeRolloutJournal $journal = null,
        ?RecordingCacheInvalidator $invalidator = null,
        int $chunkSize = 500,
    ): DefaultEntitlementRollout {
        return new DefaultEntitlementRollout(
            $journal ?? $this->rolloutJournal(),
            $invalidator ?? $this->recordingInvalidator(),
            new NullLogger,
            $chunkSize,
        );
    }
}
