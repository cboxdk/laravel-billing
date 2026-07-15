<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * A request to create a gateway PaymentIntent that charges an invoice ON-SESSION — the
 * customer is present and the product mounts the gateway's own payment element to confirm
 * client-side (and complete any SCA challenge). The engine never sees card data; it only
 * asks the gateway to create the intent and hands back the resulting client secret.
 *
 * Gateway-agnostic: the adapter reads the amount + reference and creates its own intent.
 *
 * `idempotencyKey` is scoped to this creation so a retried request (a double-submit, a
 * network retry) collapses to a single gateway intent rather than creating two. When
 * `paymentMethodId` is set the adapter confirms the intent against an already-saved
 * method; otherwise the element collects one.
 */
readonly class PaymentIntentRequest
{
    public function __construct(
        public string $account,
        public string $reference,
        public Money $amount,
        public string $idempotencyKey,
        public ?string $paymentMethodId = null,
    ) {}
}
