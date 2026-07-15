<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\ValueObjects;

/**
 * The transaction boundary of a rollout: the set of per-org applications the journal must
 * commit ATOMICALLY. Every application in a chunk — each org's grant write and its audit
 * row — commits together or not at all, so a crash mid-chunk cannot leave some orgs
 * updated (and audited) while others are not, and a re-run cannot double-write audit rows.
 *
 * The bulk path builds one chunk per configured chunk-size slice of the no-override cohort;
 * the override path builds a single-application chunk per org (written, then busted). The
 * `rolloutId` and `plan` are carried so the journal can stamp each audit row. Immutable.
 */
readonly class RolloutChunk
{
    /**
     * @param  list<RolloutApplication>  $applications
     */
    public function __construct(
        public string $rolloutId,
        public string $plan,
        public array $applications,
    ) {}

    public function size(): int
    {
        return count($this->applications);
    }
}
