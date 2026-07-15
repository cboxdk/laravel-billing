<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

use Cbox\Billing\Payment\Webhook\DefaultWebhookIngest;

/**
 * Wire up the real webhook ingest in tests over shared in-memory collaborators, so the
 * package dogfoods its own {@see DefaultWebhookIngest} rather than a mock:
 *
 *     $outcome = $this->webhookIngest()->ingest($event);
 *     expect($outcome->applied())->toBeTrue()
 *         ->and($this->webhookApplier()->timesPaid($event->reference))->toBe(1);
 *
 * The ingest shares one event-id store, one settle-once store, and one applier with the
 * test, so assertions read the very instances the flow wrote to. Arm a crash with
 * {@see FakeInvoicePaymentApplier::crashOnNextApply()} on {@see InteractsWithWebhooks::webhookApplier()}.
 */
trait InteractsWithWebhooks
{
    private ?FakeProcessedEventStore $webhookProcessedInstance = null;

    private ?FakeSettledPaymentStore $webhookSettledInstance = null;

    private ?FakeInvoicePaymentApplier $webhookApplierInstance = null;

    protected function webhookIngest(): DefaultWebhookIngest
    {
        return new DefaultWebhookIngest(
            $this->webhookProcessed(),
            $this->webhookSettled(),
            $this->webhookApplier(),
        );
    }

    protected function webhookProcessed(): FakeProcessedEventStore
    {
        return $this->webhookProcessedInstance ??= new FakeProcessedEventStore;
    }

    protected function webhookSettled(): FakeSettledPaymentStore
    {
        return $this->webhookSettledInstance ??= new FakeSettledPaymentStore;
    }

    protected function webhookApplier(): FakeInvoicePaymentApplier
    {
        return $this->webhookApplierInstance ??= new FakeInvoicePaymentApplier;
    }
}
