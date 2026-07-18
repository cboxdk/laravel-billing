<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\Testing;

use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;

/**
 * A configurable {@see RetentionOffers} for tests — stands in for the app's basic default or
 * the plugin's rich offer logic. Give it the offers to present with {@see present()}; it
 * returns them for every `(account, subscription)`, so a test can assert the seam surfaces the
 * configured offers with the right typed params (and the Null default returns none without one).
 */
class FakeRetentionOffers implements RetentionOffers
{
    /** @var list<SaveOffer> */
    private array $offers = [];

    public function present(SaveOffer ...$offers): self
    {
        $this->offers = array_values($offers);

        return $this;
    }

    public function offersFor(string $account, string $subscriptionId): array
    {
        return $this->offers;
    }
}
