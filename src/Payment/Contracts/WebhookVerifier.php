<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * Proves an inbound gateway webhook authentic and normalizes it. A gateway adapter
 * implements this by wrapping its SDK's own signature verifier, then mapping the vendor
 * event onto a {@see WebhookEvent}. The engine stays gateway-agnostic: it only ever sees
 * verified, normalized events.
 *
 * Deny-by-default: an implementation MUST throw {@see WebhookVerificationFailed} for
 * anything it cannot prove authentic — it never returns a partially-trusted or
 * best-effort event. Because the only way out is a verified {@see WebhookEvent} or an
 * exception, a caller cannot accidentally act on an unverified payload.
 */
interface WebhookVerifier
{
    /**
     * @throws WebhookVerificationFailed when the payload is not provably authentic.
     */
    public function verify(WebhookPayload $payload): WebhookEvent;
}
