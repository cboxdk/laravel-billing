---
title: Ingest a payment webhook
description: Apply a gateway webhook exactly once — deduping redeliveries and guarding already-settled payments.
weight: 47
---

# Ingest a payment webhook

Gateway webhooks are at-least-once and unordered. `WebhookIngest` makes applying
one exactly-once. Your controller's only job is to build the canonical
`WebhookEvent` from the (verified) payload and hand it over.

## Ingest

```php
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Enums\WebhookIngestStatus;

public function handle(Request $request, WebhookIngest $ingest): Response
{
    // The adapter's WebhookVerifier authenticates the payload and maps it to a WebhookEvent.
    $event = $this->gatewayAdapter->parse($request);

    $outcome = $ingest->ingest($event);

    return response()->noContent(match ($outcome->status) {
        WebhookIngestStatus::Applied,
        WebhookIngestStatus::AlreadySettled,
        WebhookIngestStatus::DuplicateEvent,
        WebhookIngestStatus::Ignored => 200, // always ack so the gateway stops retrying
    });
}
```

`DefaultWebhookIngest`:

- **verifies** the payload (`WebhookVerifier`; the default `DenyingWebhookVerifier`
  rejects everything until an adapter binds a real one);
- **dedups** on the event id via `ProcessedEventStore` → `DuplicateEvent` on a
  redelivery;
- **guards** the settled state via `SettledPaymentStore` → `AlreadySettled` if the
  invoice was settled by another path;
- otherwise **applies** the payment to the invoice (`InvoicePaymentApplier`) →
  `Applied`.

Acknowledge every terminal outcome with a `200` so the gateway stops retrying — the
dedup and settled guards mean a retry that does slip through is a no-op.

## Related

- [Payments & dunning](../core-concepts/payments-and-dunning.md)
- [Payment gateways](../extension-points/payment-gateways.md)
