<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Enums;

/**
 * The outcome of ingesting one verified webhook event, from the exactly-once ingest's
 * point of view:
 *
 *  - Applied        — the paid effect was applied to the invoice for the first time.
 *  - AlreadySettled — the payment/invoice reference was already settled; a no-op. This
 *                     is the guard that makes a re-delivery (or a re-run after a crash
 *                     that DID persist) collapse to nothing.
 *  - DuplicateEvent — this exact gateway event id was already processed; a no-op.
 *  - Ignored        — a well-formed, verified event that carries no settle effect
 *                     (a failure/pending notice); recorded, but nothing is applied.
 *  - RequiresAction — a verified SCA challenge (3-D Secure); recorded/deduped but NOT
 *                     applied. The caller must prompt the customer to complete the
 *                     challenge on the gateway's element — activation waits for the
 *                     subsequent `PaymentSettled` webhook, never this notice.
 *  - Processing     — the gateway accepted the charge but has not confirmed settlement;
 *                     recorded/deduped but NOT applied. Nothing to do but wait for the
 *                     settle (or fail) webhook.
 */
enum WebhookIngestStatus: string
{
    case Applied = 'applied';
    case AlreadySettled = 'already_settled';
    case DuplicateEvent = 'duplicate_event';
    case Ignored = 'ignored';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';

    /** Whether this ingest was the one that applied the effect. */
    public function applied(): bool
    {
        return $this === self::Applied;
    }

    /**
     * Whether this ingest surfaced a live SCA challenge the caller must prompt the
     * customer to complete on-session. Nothing was applied.
     */
    public function requiresCustomerAction(): bool
    {
        return $this === self::RequiresAction;
    }
}
