<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Contracts;

use Cbox\Billing\Entitlement\Rollout\ValueObjects\RolloutChunk;

/**
 * The transactional sink a rollout writes through. Its one job is atomicity per chunk:
 * {@see commit()} applies EVERY application in the chunk — each org's grant write AND its
 * audit row — inside ONE transaction, so a crash mid-chunk cannot leave some orgs updated
 * and audited while others are half-written, and cannot leave duplicate audit rows behind.
 *
 * It MUST be idempotent: committing a chunk whose `(rolloutId, org)` rows already exist
 * upserts them rather than duplicating, so re-running a rollout after a partial failure is
 * safe.
 *
 * The shipped in-memory journal is the zero-config default and the base the testing fake
 * extends; a host with durable entitlement rows binds a connection-backed journal that
 * wraps each chunk in a real database transaction.
 */
interface RolloutJournal
{
    /**
     * Apply and audit every application in the chunk atomically. On any failure the whole
     * chunk is rolled back — nothing in it is persisted.
     */
    public function commit(RolloutChunk $chunk): void;
}
