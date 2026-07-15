<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Payment\Contracts\WebhookVerifier;

/**
 * The raw, untrusted bytes of an inbound gateway webhook as received off the wire: the
 * exact request body and its headers. Nothing here is trusted — a {@see WebhookVerifier}
 * turns it into a {@see WebhookEvent} only after proving authenticity, and refuses
 * otherwise.
 *
 * Gateway-agnostic: each adapter reads its own signature header out of `$headers` (by
 * whatever name that gateway uses) and hands the body to its SDK's verifier. Header
 * names are matched case-insensitively via {@see WebhookPayload::header()}.
 */
readonly class WebhookPayload
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $body,
        public array $headers = [],
    ) {}

    /** The named header, matched case-insensitively; null when absent. */
    public function header(string $name): ?string
    {
        $needle = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $needle) {
                return $value;
            }
        }

        return null;
    }
}
