---
title: Payment gateways
description: Implement the gateway-agnostic PaymentGateway and WebhookVerifier contracts — Stripe and Mollie are opt-in adapter packages.
weight: 63
---

# Payment gateways

The library depends on no payment SDK. A gateway is anything that implements
`PaymentGateway`; the bundled `ManualPaymentGateway` handles offline collection
with no external dependency. Stripe and Mollie live in separate opt-in adapter
packages (`cboxdk/laravel-billing-stripe`, `-mollie`).

## Implement the gateway

```php
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\{PaymentIntent, PaymentResult, RefundIntent};

final class StripeGateway implements PaymentGateway
{
    public function name(): string { return 'stripe'; }

    public function charge(PaymentIntent $intent): PaymentResult { /* … */ }

    public function refund(RefundIntent $intent): PaymentResult { /* … */ }
}
```

Bind it over the default:

```php
$this->app->bind(PaymentGateway::class, StripeGateway::class);
```

## Verify webhooks

Webhook authentication is the adapter's job. Implement `WebhookVerifier` to
authenticate the raw payload and map it to a canonical `WebhookEvent`, then bind it
so `DefaultWebhookIngest` can use it:

```php
use Cbox\Billing\Payment\Contracts\WebhookVerifier;

final class StripeWebhookVerifier implements WebhookVerifier { /* … */ }
```

The default binding is `DenyingWebhookVerifier`, which rejects everything — so an
un-configured deployment fails closed rather than trusting unsigned payloads. Once
verified, [`WebhookIngest`](../core-concepts/payments-and-dunning.md) handles
exactly-once application (dedup + settled guard). See
[cookbook: ingest a payment webhook](../cookbook/ingest-a-payment-webhook.md).

## Related

- [Payments & dunning](../core-concepts/payments-and-dunning.md)
- [Contracts & bindings](contracts-and-bindings.md)
