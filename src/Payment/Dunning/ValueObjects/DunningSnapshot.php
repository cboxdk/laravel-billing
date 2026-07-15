<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\ValueObjects;

use Cbox\Billing\Account\Enums\AccountStandingState;

/**
 * The complete, self-contained input to a dunning decision for one account: the
 * account id, its invoices as dunning sees them, its current standing, the progress of
 * its notice sequence, and whether it is on the delinquent allow-list (bypass). The
 * policy reads only this — it performs no I/O — which is what makes the decision a pure
 * function the runner assembles and the tests can drive deterministically.
 */
readonly class DunningSnapshot
{
    /**
     * @param  list<DelinquentInvoice>  $invoices
     */
    public function __construct(
        public string $account,
        public array $invoices,
        public AccountStandingState $standing,
        public DunningState $state,
        public bool $bypassed = false,
    ) {}

    /** Whether any invoice is written off — such debt keeps a suspended account suspended. */
    public function hasUncollectible(): bool
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->isUncollectible()) {
                return true;
            }
        }

        return false;
    }

    /** Whether any invoice is still open, at any age — the strict "debt not yet cleared" test used for restore. */
    public function hasOpenInvoice(): bool
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->isOutstanding()) {
                return true;
            }
        }

        return false;
    }
}
