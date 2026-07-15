<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Payment\Enums\PaymentIntentStatus;

/**
 * The gateway-agnostic result of creating a SetupIntent — everything a product's frontend
 * needs to mount the gateway's element and save a payment method for OFF-SESSION use. No
 * charge is made; the saved method is what later renewals bill.
 *
 * `$gateway` names the adapter to load the client SDK for; `$publishableKey` is that
 * gateway's public key for the element; `$clientSecret` is the reference the element
 * confirms against. All three are null on a gateway with no client-side element (the
 * manual gateway saves nothing to charge later — it is reconciled out of band).
 * `$reference` is the gateway's own setup-intent handle for reconciliation.
 */
readonly class SetupIntentResult
{
    public function __construct(
        public string $gateway,
        public ?string $publishableKey,
        public ?string $clientSecret,
        public PaymentIntentStatus $status,
        public string $reference,
    ) {}
}
