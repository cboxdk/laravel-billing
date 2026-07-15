<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

use Cbox\Billing\Payment\ValueObjects\IngestOutcome;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;

/**
 * The single, idempotent entry point a host calls with a verified {@see WebhookEvent}. It
 * applies the event's effect to the invoice EXACTLY ONCE, keyed on the payment/invoice
 * reference, so neither a gateway re-delivery nor a host crash between "handler returned"
 * and "host persisted" can double-apply — and neither can silently drop the settlement.
 *
 * This replaces the old shape where an adapter's handler returned a bare result for the
 * host to apply unguarded: post-release the adapters call {@see WebhookIngest::ingest()}
 * instead, so the apply is atomic-and-idempotent inside the engine rather than left to
 * each host.
 */
interface WebhookIngest
{
    public function ingest(WebhookEvent $event): IngestOutcome;
}
