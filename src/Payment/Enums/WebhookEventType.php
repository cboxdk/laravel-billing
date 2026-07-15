<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Enums;

/**
 * The normalized kind of a verified gateway webhook, gateway-agnostic. Each adapter
 * maps its own vendor event names onto this narrow set so the engine reasons about one
 * vocabulary. Only {@see WebhookEventType::PaymentSettled} carries the paid effect the
 * ingest applies; the rest are recorded and observed but move no money.
 *
 * Strong Customer Authentication (SCA / 3-D Secure) is represented explicitly:
 * {@see WebhookEventType::RequiresAction} means the customer must complete an
 * authentication challenge on the gateway's own element before the charge can settle,
 * and {@see WebhookEventType::Processing} means the gateway has accepted the charge but
 * has not yet confirmed settlement. Neither activates anything — a subscription is only
 * activated / an invoice only marked paid on {@see WebhookEventType::PaymentSettled}, and
 * never on a client-side confirmation. Collapsing these into settled/failed/pending is
 * insufficient for element integration, so they are first-class members.
 *
 * Deliberately narrow — anything richer belongs to the gateway's own event stream, not
 * to the engine's settle seam.
 */
enum WebhookEventType: string
{
    case PaymentSettled = 'payment.settled';
    case PaymentFailed = 'payment.failed';
    case PaymentPending = 'payment.pending';
    case RequiresAction = 'payment.requires_action';
    case Processing = 'payment.processing';

    /** Whether this event settles a payment and therefore applies the paid effect. */
    public function isSettlement(): bool
    {
        return $this === self::PaymentSettled;
    }

    /**
     * Whether this event needs the customer prompted on-session — it carries a live SCA
     * challenge ({@see WebhookEventType::RequiresAction}) the payment element must
     * complete before the charge can settle.
     */
    public function requiresCustomerAction(): bool
    {
        return $this === self::RequiresAction;
    }
}
