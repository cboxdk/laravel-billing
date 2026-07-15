<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Exceptions;

use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use RuntimeException;

/**
 * Thrown when an inbound webhook cannot be proven authentic — a bad or missing
 * signature, an unknown event shape, or no verifier configured at all. Deny-by-default:
 * the engine refuses rather than trusting an unverified payload, so a forged webhook can
 * never reach the ingest.
 */
class WebhookVerificationFailed extends RuntimeException
{
    public static function unsigned(): self
    {
        return new self('Webhook rejected: signature missing or unverifiable.');
    }

    public static function noVerifierConfigured(): self
    {
        return new self('Webhook rejected: no verifier is bound, so nothing is trusted (deny-by-default). Bind a gateway adapter to '.WebhookVerifier::class.'.');
    }
}
