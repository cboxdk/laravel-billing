<?php

declare(strict_types=1);

namespace Cbox\Billing\Seller;

use Cbox\Billing\Seller\Contracts\EntityRouter;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Geo\ValueObjects\Jurisdiction;

/**
 * Routes to the entity registered in the buyer's country (so the supply is
 * domestic and taxed locally); otherwise falls back to the default entity, which
 * sells cross-border. This is the common seller-of-record policy — override the
 * contract for anything more specific (per-product, per-contract).
 */
readonly class DefaultEntityRouter implements EntityRouter
{
    /**
     * @param  list<SellerEntity>  $entities
     */
    public function __construct(
        private array $entities,
        private SellerEntity $default,
    ) {}

    public function routeFor(Jurisdiction $buyer): SellerEntity
    {
        foreach ($this->entities as $entity) {
            if ($entity->isRegisteredIn($buyer->country)) {
                return $entity;
            }
        }

        return $this->default;
    }
}
