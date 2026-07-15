<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Testing;

use Cbox\Billing\Entitlement\Contracts\EntitlementWriter;
use Cbox\Billing\Entitlement\ValueObjects\Entitlement;

/**
 * Records entitlements with per-product upsert semantics: keyed by `sourceRef`, so
 * setting one product does not disturb another, and an organization can hold many.
 */
class FakeEntitlementWriter implements EntitlementWriter
{
    /** @var array<string, Entitlement> keyed by sourceRef */
    public array $entitlements = [];

    /** @var list<array{organizationId: string, sourceRef: string}> */
    public array $revoked = [];

    public function set(Entitlement $entitlement): void
    {
        $this->entitlements[$entitlement->sourceRef] = $entitlement;
    }

    public function revoke(string $organizationId, string $sourceRef): void
    {
        unset($this->entitlements[$sourceRef]);
        $this->revoked[] = ['organizationId' => $organizationId, 'sourceRef' => $sourceRef];
    }

    /**
     * The entitlements currently held by an organization, across all its products.
     *
     * @return list<Entitlement>
     */
    public function forOrganization(string $organizationId): array
    {
        return array_values(array_filter(
            $this->entitlements,
            static fn (Entitlement $e): bool => $e->organizationId === $organizationId,
        ));
    }
}
