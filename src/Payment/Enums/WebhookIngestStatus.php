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
 */
enum WebhookIngestStatus: string
{
    case Applied = 'applied';
    case AlreadySettled = 'already_settled';
    case DuplicateEvent = 'duplicate_event';
    case Ignored = 'ignored';

    /** Whether this ingest was the one that applied the effect. */
    public function applied(): bool
    {
        return $this === self::Applied;
    }
}
