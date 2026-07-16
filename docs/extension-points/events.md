---
title: Domain events
description: The billing lifecycle events the engine dispatches, their payloads, when they fire, and how a plugin listens.
weight: 64
---

# Domain events

The engine dispatches plain domain events at its real lifecycle points, so a plugin or
host reacts by **registering a listener** rather than by editing the engine or wrapping
its services. This is the seam that keeps the "zero edits to the open app" plugin model:
you never call the engine to be notified — the engine calls you.

Every event is a plain `readonly` class under `Cbox\Billing\Events\`, carrying the real
aggregate or value object the operation produced (not just an id), so a listener needs no
follow-up lookup. Nothing is `final` — subclass or decorate freely.

Events are dispatched through Laravel's injected `Illuminate\Contracts\Events\Dispatcher`.
The engine's module service providers wire the real dispatcher; the dispatcher is an
**optional, trailing** constructor dependency on each emitting service, so direct
instantiation (and every existing call site) keeps working unchanged and simply dispatches
nothing when no dispatcher is supplied.

## The events

| Event | Fires when | Payload |
| --- | --- | --- |
| `Events\InvoiceIssued` | `Invoice\DefaultInvoicer::issue()` finalizes an invoice (after the currency lock stamps) | `Invoice $invoice`, `string $account` |
| `Events\PaymentSettled` | `Payment\Webhook\DefaultWebhookIngest::ingest()` applies a settlement to an invoice | `string $reference`, `Money $amount`, `PaymentResult $result` |
| `Events\CreditNoteIssued` | `Refund\DefaultRefunder::refund()` issues a credit note and records the refund | `CreditNote $creditNote` |
| `Events\SubscriptionRenewed` | `Subscription\SubscriptionManager::renew()` advances a subscription onto its next period | `Subscription $previous`, `Subscription $subscription` |
| `Events\SubscriptionChanged` | `Subscription\SubscriptionManager::scheduleChange()` pins (or replaces) a price change | `Subscription $subscription`, `ScheduledChange $change` |
| `Events\LicenseIssued` | `Licensing\LicenseMint::issue()` signs a license (and via `reissue()`, a renewal) | `IssuedLicense $license` |

### When each fires — the exact contract

- **`InvoiceIssued`** fires once per issued invoice, *after* finalization succeeds. A
  refused issuance — a tax-pending quote, or a billing-currency mismatch — throws before
  the event fires, so a listener only ever sees a real, numbered invoice.
- **`PaymentSettled`** fires once per settled reference, on the ingest call that actually
  applies the paid effect. A duplicate gateway event id, or a re-delivery of an
  already-settled reference, collapses to a no-op and does **not** re-fire — so a listener
  can treat it as exactly-once per reference. `result->gatewayReference` echoes the gateway
  event id for reconciliation.
- **`CreditNoteIssued`** fires once per issued credit note. An idempotent replay of an
  already-refunded request returns the existing refund without re-firing.
- **`SubscriptionRenewed`** fires on a genuine renewal (the period advanced, price carried
  over or a due scheduled change re-pinned). A renewal that instead enacts a due
  cancellation (`cancelAtPeriodEnd`) ends the subscription and does **not** fire this.
- **`SubscriptionChanged`** fires when a plan change is scheduled. The change takes effect
  at `change->effectiveAt` and is enacted on the next `renew()`; the event carries both the
  subscription (with the change pinned) and the `ScheduledChange` describing what changes
  and when.
- **`LicenseIssued`** fires for every mint. A `reissue()` (a renewal) runs through
  `issue()`, so it fires again for the fresh, independently-revocable license.

## A plugin listens like this

Register a listener in a service provider that boots after the package — no engine edits:

```php
use Cbox\Billing\Events\InvoiceIssued;
use Illuminate\Contracts\Events\Dispatcher;

public function boot(Dispatcher $events): void
{
    $events->listen(InvoiceIssued::class, function (InvoiceIssued $event): void {
        // React to the real aggregate — deliver the invoice, notify accounting, etc.
        $invoice = $event->invoice;

        MyPlugin::deliverInvoice($event->account, $invoice->number, $invoice->totals->gross);
    });
}
```

Or with a dedicated listener class and the array map (`Event::listen` / a provider's
`$listen`), the idiomatic Laravel way:

```php
protected $listen = [
    \Cbox\Billing\Events\PaymentSettled::class => [
        \MyPlugin\Listeners\MarkAccountCurrent::class,
    ],
];
```

Listeners are thin adapters over your own domain services (the same rule the engine's own
jobs and listeners follow). Keep orchestration in a service the listener calls.

## Testing your listeners

Because dispatch goes through the container's dispatcher, `Event::fake()` captures every
billing event in a test, and you assert against the real vector:

```php
Event::fake();

$invoice = app(Invoicer::class)->issue($quote, $seller, 'acme', $now);

Event::assertDispatched(InvoiceIssued::class, fn (InvoiceIssued $e) => $e->invoice === $invoice);
```
