<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Enums;

/**
 * The normalized kind of a verified gateway webhook, gateway-agnostic. Each adapter
 * maps its own vendor event names onto this narrow set so the engine reasons about one
 * vocabulary. Only {@see WebhookEventType::PaymentSettled} carries the paid effect the
 * ingest applies; the rest are recorded and observed but move no money.
 *
 * Deliberately narrow — anything richer belongs to the gateway's own event stream, not
 * to the engine's settle seam.
 */
enum WebhookEventType: string
{
    case PaymentSettled = 'payment.settled';
    case PaymentFailed = 'payment.failed';
    case PaymentPending = 'payment.pending';

    /** Whether this event settles a payment and therefore applies the paid effect. */
    public function isSettlement(): bool
    {
        return $this === self::PaymentSettled;
    }
}
