<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

/**
 * First-sight dedup for gateway webhook events, keyed on the gateway's own event id. It
 * is the general "have I already processed this exact event" ledger a re-delivery
 * collapses against.
 *
 * The authoritative exactly-once guard for the paid EFFECT is {@see SettledPaymentStore}
 * (keyed on the payment/invoice reference); this store dedups the event stream itself —
 * including events that carry no settle effect.
 */
interface ProcessedEventStore
{
    /**
     * Record first sight of `$eventId`. Returns true the first time the id is seen (the
     * caller should process it) and false on every re-sight (a no-op re-delivery).
     */
    public function remember(string $eventId): bool;
}
