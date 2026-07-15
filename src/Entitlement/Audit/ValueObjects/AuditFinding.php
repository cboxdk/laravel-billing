<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\ValueObjects;

use Cbox\Billing\Entitlement\Audit\Enums\EntitlementOutageKind;

/**
 * An outage-class finding: an org/plan whose resolved entitlements do NOT cover the
 * expected keys. A finding is only ever produced for a genuine gap — a clean org
 * produces none — so every finding is outage-worthy by construction.
 *
 *  - `expectedKeys` — what the plan/catalog state said the org should resolve.
 *  - `missingKeys`  — expected keys that resolved dark (absent row => deny-by-default,
 *                     or a present-but-disabled policy). These are the units of traffic
 *                     the org is being silently refused.
 *  - `resolvedKeys` — expected keys that resolved to a live, enabled policy.
 *
 * `missingKeys` is guaranteed non-empty. When it equals `expectedKeys` the org is
 * refused everything on the plan ({@see EntitlementOutageKind::AllDisabled}); otherwise
 * the plan is partially dark ({@see EntitlementOutageKind::MissingExpected}). Immutable.
 */
readonly class AuditFinding
{
    /**
     * @param  list<string>  $expectedKeys
     * @param  list<string>  $missingKeys  non-empty; a subset of $expectedKeys
     * @param  list<string>  $resolvedKeys
     */
    public function __construct(
        public string $org,
        public string $plan,
        public array $expectedKeys,
        public array $missingKeys,
        public array $resolvedKeys,
    ) {}

    /**
     * True when EVERY expected key resolved dark — the org is refused the whole plan.
     * This is the signature-blind outage in its purest form: nothing resolves, yet a
     * check that compares the rows to themselves would see a perfectly consistent
     * (empty) set.
     */
    public function isAllDisabled(): bool
    {
        return $this->resolvedKeys === [];
    }

    /** The blast radius of this finding — total vs partial. Both are outage-class. */
    public function kind(): EntitlementOutageKind
    {
        return $this->isAllDisabled()
            ? EntitlementOutageKind::AllDisabled
            : EntitlementOutageKind::MissingExpected;
    }
}
