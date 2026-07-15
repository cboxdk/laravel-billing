<?php

declare(strict_types=1);

namespace Cbox\Billing\Seller\Contracts;

use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Geo\ValueObjects\Jurisdiction;

/**
 * Chooses which selling entity issues an invoice for a given buyer — the
 * multi-entity routing policy. The chosen entity drives the tax outcome, so
 * routing is a tax decision, not just branding.
 */
interface EntityRouter
{
    public function routeFor(Jurisdiction $buyer): SellerEntity;
}
