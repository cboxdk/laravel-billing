<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * A scripted {@see WebhookVerifier} for tests. Constructed with the verified event a
 * genuine adapter would produce, it echoes that event; constructed to reject, it throws
 * {@see WebhookVerificationFailed} like an adapter faced with a bad signature — so a test
 * can drive both the accepted and the deny-by-default rejected path without a real SDK.
 */
class FakeWebhookVerifier implements WebhookVerifier
{
    /** @var list<WebhookPayload> */
    public array $verified = [];

    private function __construct(
        private readonly ?WebhookEvent $event,
    ) {}

    /** A verifier that accepts any payload and returns `$event`. */
    public static function accepting(WebhookEvent $event): self
    {
        return new self($event);
    }

    /** A verifier that rejects every payload, as an adapter does for a bad signature. */
    public static function rejecting(): self
    {
        return new self(null);
    }

    public function verify(WebhookPayload $payload): WebhookEvent
    {
        $this->verified[] = $payload;

        if ($this->event === null) {
            throw WebhookVerificationFailed::unsigned();
        }

        return $this->event;
    }
}
