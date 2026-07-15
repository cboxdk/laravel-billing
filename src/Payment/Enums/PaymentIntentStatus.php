<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Enums;

/**
 * The lifecycle state of a gateway payment- or setup-intent, gateway-agnostic. Each
 * adapter maps its own intent status onto this narrow set so a product's frontend can
 * drive the gateway's element from one vocabulary.
 *
 *  - RequiresPaymentMethod — no usable payment method yet; the element must collect one.
 *  - RequiresAction        — a Strong Customer Authentication (SCA / 3-D Secure)
 *                            challenge must be completed on the element before the intent
 *                            can proceed.
 *  - Processing            — the gateway has accepted the confirmation and is settling
 *                            out of band; the outcome arrives on the webhook.
 *  - Succeeded             — the intent completed. For a payment intent the charge
 *                            captured; for a setup intent the method is saved. NOTE this
 *                            is the client-side view only — the engine still activates /
 *                            marks paid strictly on the `PaymentSettled` webhook, never on
 *                            this status alone.
 *  - Canceled              — the intent was canceled and cannot be confirmed.
 */
enum PaymentIntentStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';

    /**
     * Whether the customer must be prompted on-session to complete an SCA challenge on the
     * gateway's element before this intent can proceed.
     */
    public function requiresCustomerAction(): bool
    {
        return $this === self::RequiresAction;
    }
}
