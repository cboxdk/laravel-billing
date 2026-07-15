<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Testing;

use Cbox\Billing\Entitlement\Rollout\Contracts\CacheInvalidator;

/**
 * A recording {@see CacheInvalidator} for tests: it fires no real bust, it just remembers
 * every org it was asked to invalidate, in order. That is exactly what proves the storm
 * avoidance — a test asserts the bulk cohort left {@see bustCount()} at ZERO (invalidation
 * relied on TTL) while the override cohort produced exactly one bust per org.
 */
class RecordingCacheInvalidator implements CacheInvalidator
{
    /** @var list<string> */
    private array $busted = [];

    public function invalidate(string $organizationId): void
    {
        $this->busted[] = $organizationId;
    }

    /** @return list<string> every org busted, in order. */
    public function busted(): array
    {
        return $this->busted;
    }

    public function bustCount(): int
    {
        return count($this->busted);
    }

    /** How many times a specific org was busted (proves exactly-once per override org). */
    public function bustCountFor(string $org): int
    {
        return count(array_filter($this->busted, static fn (string $o): bool => $o === $org));
    }
}
