---
title: Payments & dunning
description: Gateway-agnostic charging, a delinquency/dunning policy that suspends access without touching money, and an exactly-once webhook ingest seam.
weight: 30
---

# Payments & dunning

## Gateway-agnostic charging

`PaymentGateway` is the seam every gateway implements; the library depends on no
gateway SDK:

```php
interface PaymentGateway
{
    public function name(): string;
    public function charge(PaymentIntent $intent): PaymentResult;
    public function refund(RefundIntent $intent): PaymentResult;
}
```

`ManualPaymentGateway` is a dependency-free implementation for offline / manual
collection. Stripe and Mollie are separate opt-in adapter packages that bind this
contract. See [payment gateways](../extension-points/payment-gateways.md).

## Dunning

Dunning chases an unpaid, past-due account and, ultimately, suspends it —
suspension gates **access only** (it flips [account standing](accounts.md)) and
never touches credit balances or the ledger.

`DelinquencyPolicy` decides the next action from a snapshot:

```php
interface DelinquencyPolicy
{
    public function decide(DunningSnapshot $snapshot, DunningConfig $config, DateTimeImmutable $now): DunningOutcome;
}
```

`DefaultDelinquencyPolicy` reads `config('billing.payment.dunning')`:

- **`grace_hours`** — an invoice fresher than this past its due instant is a
  just-missed payment and is not dunned.
- **`notice_frequency_days`** — the minimum cadence between reminders.
- **`min_notice_count`** — an account is never suspended un-warned; this many
  notices must go out first, even past the day threshold.
- **`max_delinquency_days`** — once the oldest past-due invoice is this old **and**
  the minimum notices have gone out, the account escalates to suspension.

Restore is deliberately strict: an account is lifted back to access only once
**all** its debt is cleared and none is written off (uncollectible), so paying part
of a bill never silently reopens the door. `DunningRunner` drives a cohort through
the policy; `DunningPolicy` holds the simple retry-delay schedule
(`retryDelayForAttempt`, default `[1, 3, 5]` days). A `DelinquentAllowList` exempts
specific accounts.

## Webhook ingest: exactly-once

Gateway webhooks are at-least-once and unordered. `WebhookIngest` is the canonical
seam that makes applying one **exactly-once**:

```php
interface WebhookIngest
{
    public function ingest(WebhookEvent $event): IngestOutcome;
}
```

`DefaultWebhookIngest` verifies the payload (`WebhookVerifier` — `DenyingWebhookVerifier`
is the safe default until an adapter binds a real one), then dedups on the event id
via a `ProcessedEventStore` and guards the settled state via a `SettledPaymentStore`,
returning a `WebhookIngestStatus`:

```php
enum WebhookIngestStatus: string
{
    case Applied = 'applied';
    case AlreadySettled = 'already_settled';
    case DuplicateEvent = 'duplicate_event';
    case Ignored = 'ignored';
}
```

So a redelivered webhook is `DuplicateEvent`, a payment already settled by another
path is `AlreadySettled`, and only a genuinely new, verified event is `Applied` —
applying the payment to the invoice via `InvoicePaymentApplier`.

## Testing

`InteractsWithDunning`, `InteractsWithWebhooks`, `FakePaymentGateway`,
`FakeWebhookVerifier`, and the in-memory processed/settled stores drive both paths,
including duplicate and out-of-order delivery.

## Related

- [Cookbook: ingest a payment webhook](../cookbook/ingest-a-payment-webhook.md)
- [Refunds & chargebacks](refunds-and-chargebacks.md)
- [Accounts](accounts.md)
