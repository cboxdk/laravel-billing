<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Rollout\Invalidators;

use Cbox\Billing\Entitlement\Rollout\Contracts\CacheInvalidator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The default {@see CacheInvalidator}: dispatch a per-org {@see EntitlementCacheBusted}
 * event, which a host listens for to evict the org's hot-path billing cache.
 *
 * This is the collaborator the bulk path deliberately does NOT call — dispatching one of
 * these events per org across a 100k-org plan is exactly the cache storm the rollout
 * split exists to avoid. Only the per-org override path invokes it, once per org.
 */
readonly class EventCacheInvalidator implements CacheInvalidator
{
    public function __construct(private Dispatcher $events) {}

    public function invalidate(string $organizationId): void
    {
        $this->events->dispatch(new EntitlementCacheBusted($organizationId));
    }
}
