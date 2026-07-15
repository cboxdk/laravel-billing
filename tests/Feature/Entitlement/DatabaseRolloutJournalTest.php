<?php

declare(strict_types=1);

use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Enums\RolloutPath;
use Cbox\Billing\Entitlement\Rollout\Journal\DatabaseRolloutJournal;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutApplication;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutChunk;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->db = $this->app->make('db')->connection();
    $this->resolver = new EntitlementMeterPolicyResolver;
    $this->journal = new DatabaseRolloutJournal($this->db, $this->resolver);
});

function bulkChunk(string $rolloutId, string ...$orgs): RolloutChunk
{
    $applications = array_map(
        static fn (string $org): RolloutApplication => new RolloutApplication(
            $org,
            ['api.calls' => MeterPolicy::unlimited()],
            RolloutPath::Bulk,
        ),
        $orgs,
    );

    return new RolloutChunk($rolloutId, 'pro', array_values($applications));
}

it('commits a chunk to the durable audit table and applies the grants', function (): void {
    $this->journal->commit(bulkChunk('rollout_1', 'org_1', 'org_2'));

    expect($this->db->table('billing_entitlement_rollouts')->count())->toBe(2)
        ->and($this->resolver->resolve('org_1', 'api.calls'))->not->toBeNull()
        ->and($this->resolver->resolve('org_2', 'api.calls'))->not->toBeNull();

    $row = $this->db->table('billing_entitlement_rollouts')->where('org', 'org_1')->first();
    expect($row->via)->toBe('bulk')
        ->and($row->plan)->toBe('pro')
        ->and($row->meters)->toBe('api.calls');
});

it('rolls the whole chunk back inside one transaction when applying an org fails', function (): void {
    // A resolver that throws when the poisoned org's grants are applied — the fault lands
    // AFTER earlier rows in the same chunk were already inserted.
    $poisoned = new class extends EntitlementMeterPolicyResolver
    {
        public function grant(string $org, string $meter, MeterPolicy $policy): EntitlementMeterPolicyResolver
        {
            if ($org === 'org_bad') {
                throw new RuntimeException('grant write failed mid-chunk');
            }

            return parent::grant($org, $meter, $policy);
        }
    };

    $journal = new DatabaseRolloutJournal($this->db, $poisoned);

    expect(fn () => $journal->commit(bulkChunk('rollout_1', 'org_1', 'org_bad')))
        ->toThrow(RuntimeException::class);

    // The transaction rolled back: the org_1 row inserted before the fault is gone too.
    expect($this->db->table('billing_entitlement_rollouts')->count())->toBe(0);
});

it('is idempotent: re-committing the same rollout upserts, never duplicating audit rows', function (): void {
    $this->journal->commit(bulkChunk('rollout_1', 'org_1', 'org_2'));
    $this->journal->commit(bulkChunk('rollout_1', 'org_1', 'org_2'));

    // UNIQUE(rollout_id, org) → still two rows after the re-run.
    expect($this->db->table('billing_entitlement_rollouts')->count())->toBe(2);
});
