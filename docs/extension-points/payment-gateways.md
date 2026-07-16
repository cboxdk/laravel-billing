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

## Stored customers & saved methods

Beyond a single charge, the seam manages the gateway objects that off-session
renewals bill against. `createCustomer` mints (or re-resolves) the gateway customer
that saved methods and off-session charges attach to, stamping the host's account key
into its metadata for dashboard reconciliation and returning its reference (e.g.
Stripe `cus_…`); the host persists the account→reference mapping. `createSetupIntent`
returns a client secret the frontend confirms to vault a method, and
`attachPaymentMethod` / `paymentMethods` / `setDefaultPaymentMethod` list and manage
what is vaulted. `detachPaymentMethod` removes a vaulted method so future renewals can
no longer charge it — it is idempotent (detaching an already-detached method must not
error), and a vault-less gateway treats it as a no-op.

```php
$customer = $gateway->createCustomer($account, $email, $name); // "cus_…"

// After the frontend confirms a SetupIntent element:
$method = $gateway->attachPaymentMethod($account, $paymentMethodId);
$gateway->setDefaultPaymentMethod($account, $method->id);

// Tear the method down when the customer removes it:
$gateway->detachPaymentMethod($account, $method->id);
```

`ManualPaymentGateway` implements this honestly for a vault-less gateway:
`createCustomer` returns a deterministic local reference (`'manual:'.$account`) with
no external round-trip, and `detachPaymentMethod` is a genuine no-op.

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
