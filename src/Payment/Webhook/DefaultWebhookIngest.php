<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Webhook;

use Cbox\Billing\Events\PaymentSettled;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\ValueObjects\IngestOutcome;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The default exactly-once ingest. It composes the event-id dedup, the settle-once guard,
 * and the host's invoice effect into one idempotent apply.
 *
 * The ordering is what makes the apply survive a crash between "handler returned" and
 * "host persisted": the EFFECT is applied FIRST, and the durable records (settle claim,
 * processed-event id) are written only AFTER it returns. So a crash mid-apply persists
 * nothing — neither the claim nor the event id — and the gateway's re-delivery re-applies
 * exactly once; while a re-delivery after a successful apply finds the reference already
 * settled and is a no-op.
 *
 * The exactly-once guarantee is keyed on the payment/invoice reference (the natural key),
 * not the event id — two different events that both mean "invoice X paid" settle X once.
 * In production the host wraps {@see DefaultWebhookIngest::ingest()} in a single
 * transaction so the effect and the two records commit atomically; the settle claim is a
 * UNIQUE insert on the reference. There is then no window in which the effect persisted
 * but the claim did not.
 */
readonly class DefaultWebhookIngest implements WebhookIngest
{
    public function __construct(
        private ProcessedEventStore $processed,
        private SettledPaymentStore $settled,
        private InvoicePaymentApplier $applier,
        private ?Dispatcher $events = null,
    ) {}

    public function ingest(WebhookEvent $event): IngestOutcome
    {
        // A non-settlement event carries no paid effect: dedup it on the event id and
        // surface its kind so the caller can react. An SCA `RequiresAction` and a
        // `Processing` notice are recorded/deduped exactly like a failure/pending notice
        // but reported through their own status — never activated. Only the succeeded
        // webhook (below) ever moves the invoice; a client-side confirmation never does.
        if (! $event->isSettlement()) {
            if (! $this->processed->remember($event->id)) {
                return IngestOutcome::duplicateEvent($event);
            }

            return match ($event->type) {
                WebhookEventType::RequiresAction => IngestOutcome::requiresAction($event),
                WebhookEventType::Processing => IngestOutcome::processing($event),
                default => IngestOutcome::ignored($event),
            };
        }

        // The authoritative guard: has this payment/invoice reference already settled?
        // A re-delivery whose effect already persisted collapses here to a no-op.
        if ($this->settled->isSettled($event->reference)) {
            $this->processed->remember($event->id);

            return IngestOutcome::alreadySettled($event);
        }

        // Apply the effect FIRST. If the host crashes here nothing below runs, so neither
        // the settle claim nor the event id persists and the re-delivery re-applies once.
        $result = $event->toPaymentResult();
        $this->applier->markPaid($event->reference, $event->amount, $result);

        // Commit the guards. In production these commit in the same transaction as the
        // effect above, so the claim + effect are one atomic, exactly-once unit.
        $this->settled->settle($event->reference);
        $this->processed->remember($event->id);

        // Announce the settlement only on the applying call — a duplicate event or an
        // already-settled reference returned above without reaching here, so this fires
        // exactly once per settled reference.
        $this->events?->dispatch(new PaymentSettled($event->reference, $event->amount, $result));

        return IngestOutcome::applied($event);
    }
}
