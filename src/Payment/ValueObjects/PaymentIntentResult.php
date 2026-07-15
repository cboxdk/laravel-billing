<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;

/**
 * The gateway-agnostic result of creating a PaymentIntent — everything a product's
 * frontend needs to mount the gateway's element and confirm the charge client-side, and
 * nothing card-related. The engine never holds card data; it holds only the gateway's
 * `$clientSecret` reference the element completes against.
 *
 * `$gateway` names the adapter the product must load the matching client SDK for;
 * `$publishableKey` is that gateway's public key for the element. Both are null on a
 * gateway with no client-side element (e.g. the manual gateway), whose intent is settled
 * out of band rather than through a browser element.
 *
 * `$status` drives the frontend: {@see PaymentIntentStatus::RequiresAction} means an SCA
 * challenge must be completed on the element. Reaching {@see PaymentIntentStatus::Succeeded}
 * client-side does NOT activate anything — the engine marks the invoice paid strictly on
 * the gateway's `PaymentSettled` webhook.
 *
 * The optional `$amount` echoes what the intent charges (a typed {@see Money}, carrying
 * both minor units and ISO currency — never a raw float); it is null when the gateway
 * does not report it back on creation.
 */
readonly class PaymentIntentResult
{
    public function __construct(
        public string $gateway,
        public ?string $publishableKey,
        public ?string $clientSecret,
        public PaymentIntentStatus $status,
        public string $reference,
        public ?Money $amount = null,
    ) {}

    /**
     * Whether the frontend must prompt the customer to complete an SCA challenge on the
     * gateway's element before this intent can proceed.
     */
    public function requiresCustomerAction(): bool
    {
        return $this->status->requiresCustomerAction();
    }
}
