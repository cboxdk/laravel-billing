<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\ValueObjects;

/**
 * The coarse entitlement billing projects to identity — scoped to ONE product. An
 * organization can hold many products at once (the cross-product portfolio), so an
 * entitlement is keyed by (organization, product), not a single tier per org.
 * Fine-grained usage/limit checks stay on the app hot path against billing — this
 * is only the stable per-product tier cbox-id holds. `sourceRef` ties it back to
 * the billing subscription that granted it.
 */
readonly class Entitlement
{
    /**
     * @param  list<string>  $features
     */
    public function __construct(
        public string $organizationId,
        public string $productId,
        public string $tier,
        public array $features,
        public string $sourceRef,
    ) {}
}
