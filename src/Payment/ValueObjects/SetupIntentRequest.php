<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

/**
 * A request to create a gateway SetupIntent that saves a payment method for OFF-SESSION
 * use — no immediate charge. The saved method is what later renewals charge without the
 * customer present; if such a renewal comes back needing authentication it fails into
 * dunning plus a re-authenticate prompt (see ADR-0009).
 *
 * Gateway-agnostic: the adapter creates its own setup intent for `$account` and returns
 * the client secret its element confirms against. `idempotencyKey` is scoped to this
 * creation so a retry collapses to a single gateway setup intent.
 */
readonly class SetupIntentRequest
{
    public function __construct(
        public string $account,
        public string $idempotencyKey,
    ) {}
}
