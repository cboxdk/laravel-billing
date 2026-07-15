<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog;

use Cbox\Billing\Catalog\Contracts\Catalog;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the catalog to an empty in-memory default. Hosts rebind it with their
 * products/prices (or a database-backed implementation of the same contract).
 */
class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Catalog::class, static fn (): InMemoryCatalog => new InMemoryCatalog);
    }
}
