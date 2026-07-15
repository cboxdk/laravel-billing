<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\ValueObjects;

use Cbox\Billing\Entitlement\Rollout\Enums\RolloutPath;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * The resolved per-org unit of work a rollout hands the journal: the exact
 * `{meter => MeterPolicy}` to write for one org, and which path ({@see RolloutPath})
 * produced it.
 *
 * For a bulk application the grants are the plan baseline verbatim; for an override
 * application they are the baseline already overlaid with the org's tailored grants — the
 * journal never re-derives them, it just writes what it is given. Immutable.
 */
readonly class RolloutApplication
{
    /**
     * @param  array<string, MeterPolicy>  $grants  the fully-resolved policies to write
     */
    public function __construct(
        public string $org,
        public array $grants,
        public RolloutPath $via,
    ) {}
}
