<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Webhook\Storage;

use Cbox\Billing\Payment\Contracts\SettledPaymentStore;

/**
 * In-memory {@see SettledPaymentStore} — the zero-config default and the base the test
 * fake extends. Single process, not durable; production binds a durable store where
 * {@see InMemorySettledPaymentStore::settle()} is a UNIQUE insert on the reference,
 * committed in the same transaction as the invoice effect.
 */
class InMemorySettledPaymentStore implements SettledPaymentStore
{
    /** @var array<string, true> settled references */
    protected array $settled = [];

    public function settle(string $reference): bool
    {
        if (isset($this->settled[$reference])) {
            return false;
        }

        $this->settled[$reference] = true;

        return true;
    }

    public function isSettled(string $reference): bool
    {
        return isset($this->settled[$reference]);
    }
}
