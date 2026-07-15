<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Enums\WebhookIngestStatus;

/**
 * What one {@see WebhookIngest} call did: the status, the
 * gateway event id it acted on, and the payment/invoice reference it is keyed on. The
 * host logs it; only {@see IngestOutcome::applied()} moved the invoice.
 */
readonly class IngestOutcome
{
    public function __construct(
        public WebhookIngestStatus $status,
        public string $eventId,
        public string $reference,
    ) {}

    public static function applied(WebhookEvent $event): self
    {
        return new self(WebhookIngestStatus::Applied, $event->id, $event->reference);
    }

    public static function alreadySettled(WebhookEvent $event): self
    {
        return new self(WebhookIngestStatus::AlreadySettled, $event->id, $event->reference);
    }

    public static function duplicateEvent(WebhookEvent $event): self
    {
        return new self(WebhookIngestStatus::DuplicateEvent, $event->id, $event->reference);
    }

    public static function ignored(WebhookEvent $event): self
    {
        return new self(WebhookIngestStatus::Ignored, $event->id, $event->reference);
    }

    public static function requiresAction(WebhookEvent $event): self
    {
        return new self(WebhookIngestStatus::RequiresAction, $event->id, $event->reference);
    }

    public static function processing(WebhookEvent $event): self
    {
        return new self(WebhookIngestStatus::Processing, $event->id, $event->reference);
    }

    /** Whether this ingest was the one that applied the paid effect. */
    public function wasApplied(): bool
    {
        return $this->status->applied();
    }

    /**
     * Whether this ingest surfaced a live SCA challenge: the caller must prompt the
     * customer to complete it on the gateway's element. Nothing was applied.
     */
    public function requiresCustomerAction(): bool
    {
        return $this->status->requiresCustomerAction();
    }
}
