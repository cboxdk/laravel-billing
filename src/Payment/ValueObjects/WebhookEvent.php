<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Enums\WebhookEventType;

/**
 * A verified, normalized gateway webhook: the outcome of running raw {@see WebhookPayload}
 * bytes through a {@see WebhookVerifier}. Constructing one
 * asserts authenticity — an unverified event never reaches this type.
 *
 * The two identifiers do different jobs and are deduplicated on different keys:
 *  - `$id` is the GATEWAY EVENT id — the natural key for first-sight event dedup
 *    (a re-delivery of the same event carries the same id).
 *  - `$reference` is the PAYMENT/INVOICE reference the event settles — the natural key
 *    the settle-once effect is idempotent on (two different event ids that both mean
 *    "invoice X paid" still settle X exactly once).
 */
readonly class WebhookEvent
{
    public function __construct(
        public string $id,
        public WebhookEventType $type,
        public string $reference,
        public Money $amount,
    ) {}

    /** Whether this event settles a payment and so applies the paid effect. */
    public function isSettlement(): bool
    {
        return $this->type->isSettlement();
    }

    /**
     * The normalized {@see PaymentResult} this event maps to — the "mapped result" the
     * effect is applied from. A settlement echoes the gateway event id as the reference
     * for reconciliation; a failure/pending/processing/requires-action maps to the
     * matching status. Only the settlement is ever applied by the ingest; the rest are
     * carried for observation.
     */
    public function toPaymentResult(): PaymentResult
    {
        return match ($this->type) {
            WebhookEventType::PaymentSettled => PaymentResult::succeeded($this->id),
            WebhookEventType::PaymentPending, WebhookEventType::Processing => PaymentResult::pending($this->id),
            WebhookEventType::PaymentFailed => new PaymentResult(PaymentStatus::Failed, $this->id),
            WebhookEventType::RequiresAction => new PaymentResult(PaymentStatus::RequiresAction, $this->id),
        };
    }
}
