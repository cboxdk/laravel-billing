<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Journal;

use Cbox\Billing\Entitlement\Resolvers\EntitlementMeterPolicyResolver;
use Cbox\Billing\Entitlement\Rollout\Contracts\RolloutJournal;
use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutChunk;
use Illuminate\Database\ConnectionInterface;

/**
 * Durable {@see RolloutJournal}: writes each chunk's audit rows to
 * `billing_entitlement_rollouts` inside ONE real database transaction, and applies the
 * grants through the resolver in the same closure — so a fault anywhere in the chunk rolls
 * the whole chunk back. The `UNIQUE(rollout_id, org)` index makes {@see commit()}
 * idempotent: a re-run upserts each row rather than duplicating it, and the transaction
 * guarantees a partial crash never leaves a chunk half-audited.
 *
 * The audit row is inserted BEFORE the org's grants are applied, so if applying a later
 * org's grants raises, the transaction rolls back every audit row written so far in the
 * chunk — never a stray row for an org whose grants never landed.
 *
 * Bind this (over a connection whose entitlement rows are durable) when the host wants the
 * rollout audit trail to survive a process crash; the in-memory journal is the default.
 */
readonly class DatabaseRolloutJournal implements RolloutJournal
{
    private const TABLE = 'billing_entitlement_rollouts';

    public function __construct(
        private ConnectionInterface $db,
        private EntitlementMeterPolicyResolver $resolver,
    ) {}

    public function commit(RolloutChunk $chunk): void
    {
        $this->db->transaction(function () use ($chunk): void {
            foreach ($chunk->applications as $application) {
                $this->db->table(self::TABLE)->updateOrInsert(
                    ['rollout_id' => $chunk->rolloutId, 'org' => $application->org],
                    [
                        'plan' => $chunk->plan,
                        'via' => $application->via->value,
                        'meters' => implode(',', array_keys($application->grants)),
                    ],
                );

                foreach ($application->grants as $meter => $policy) {
                    $this->resolver->grant($application->org, $meter, $policy);
                }
            }
        });
    }
}
