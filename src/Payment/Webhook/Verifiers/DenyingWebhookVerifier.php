<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Webhook\Verifiers;

use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * The deny-by-default verifier bound when no gateway adapter is installed: it trusts
 * nothing and refuses every payload. A webhook is only ever accepted once a host binds a
 * real adapter-backed {@see WebhookVerifier} on this contract, so an unconfigured engine
 * can never act on an inbound payload rather than silently trusting it.
 */
readonly class DenyingWebhookVerifier implements WebhookVerifier
{
    public function verify(WebhookPayload $payload): WebhookEvent
    {
        throw WebhookVerificationFailed::noVerifierConfigured();
    }
}
