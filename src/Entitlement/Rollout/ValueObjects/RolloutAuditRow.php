<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\ValueObjects;

use Cbox\Billing\Entitlement\Rollout\Enums\RolloutPath;

/**
 * One durable audit row a rollout writes for one org: which rollout (`rolloutId`) touched
 * which org on which plan, and by which path ({@see RolloutPath}). Keyed by
 * `(rolloutId, org)` so a re-run upserts rather than duplicating — the guarantee the
 * chunk transaction and the unique index together provide. Immutable.
 */
readonly class RolloutAuditRow
{
    public function __construct(
        public string $rolloutId,
        public string $plan,
        public string $org,
        public RolloutPath $via,
    ) {}
}
