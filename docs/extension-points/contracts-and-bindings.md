---
title: Contracts & bindings
description: One contract per module, bound in its service provider — rebind to replace any capability.
weight: 61
---

# Contracts & bindings

`BillingServiceProvider` registers one service provider per module, and each binds
its contracts to a default implementation. To replace a capability, bind your own
implementation of the contract in a service provider that boots after the
package's.

## The core contracts

| Contract | Module | Default binding |
| --- | --- | --- |
| `Metering\Contracts\Enforcement` | Metering | `LeasedEnforcement` |
| `Metering\Contracts\AllowanceLeaseSource` | Metering | host-provided (use `FakeAllowanceLeaseSource` in tests) |
| `Metering\Contracts\MeterPolicyResolver` | Metering | `EntitlementMeterPolicyResolver` |
| `Metering\Contracts\EventLog` | Metering | `InMemoryEventLog` / `DatabaseEventLog` |
| `Metering\Contracts\MeterIngest` | Metering | `DefaultMeterIngest` |
| `Wallet\Contracts\Wallet` | Wallet | `InMemoryWallet` |
| `Ledger\Contracts\Ledger` | Ledger | `InMemoryLedger` / `DatabaseLedger` |
| `Ledger\Contracts\TwoPhaseLedger` | Ledger | `InMemoryTwoPhaseLedger` |
| `Reconciliation\Contracts\Reconciler` | Reconciliation | `DefaultReconciler` |
| `Reconciliation\Contracts\CheckpointStore` | Reconciliation | `InMemoryCheckpointStore` / `DatabaseCheckpointStore` |
| `Account\Contracts\BillingCurrencyLock` | Account | `InMemoryBillingCurrencyLock` / `DatabaseBillingCurrencyLock` |
| `Account\Contracts\AccountStanding` | Account | `InMemoryAccountStanding` |
| `Catalog\Contracts\Catalog` | Catalog | `InMemoryCatalog` |
| `Seller\Contracts\EntityRouter` | Seller | `DefaultEntityRouter` |
| `Quote\Contracts\QuoteBuilder` | Quote | `DefaultQuoteBuilder` |
| `Invoice\Contracts\Invoicer` | Invoice | `DefaultInvoicer` |
| `Invoice\Contracts\InvoiceNumberSequence` | Invoice | `InMemoryInvoiceNumberSequence` |
| `Payment\Contracts\PaymentGateway` | Payment | `ManualPaymentGateway` |
| `Payment\Contracts\WebhookIngest` | Payment | `DefaultWebhookIngest` |
| `Payment\Contracts\WebhookVerifier` | Payment | `DenyingWebhookVerifier` (deny until an adapter binds one) |
| `Payment\Dunning\Contracts\DelinquencyPolicy` | Payment | `DefaultDelinquencyPolicy` |
| `Refund\Contracts\Refunder` | Refund | `DefaultRefunder` |
| `Refund\Contracts\ChargebackHandler` | Refund | `DefaultChargebackHandler` |
| `Entitlement\Contracts\EntitlementProjector` | Entitlement | `DefaultEntitlementProjector` |
| `Entitlement\Contracts\EntitlementWriter` | Entitlement | `NullEntitlementWriter` (host binds the real one) |
| `Entitlement\Rollout\Contracts\EntitlementRollout` | Entitlement | `DefaultEntitlementRollout` |
| `Entitlement\Audit\Contracts\EntitlementAudit` | Entitlement | `DefaultEntitlementAudit` |

## Rebinding

```php
use Cbox\Billing\Payment\Contracts\PaymentGateway;

public function register(): void
{
    $this->app->bind(PaymentGateway::class, StripeGateway::class);
}
```

Two contracts you almost always bind yourself in production: the
`AllowanceLeaseSource` (your billing-side allowance authority) and the
`EntitlementWriter` (cbox-id ships the receiving contract). The rest have working
defaults.

## Related

- [Storage adapters](storage-adapters.md)
- [Payment gateways](payment-gateways.md)
- [Testing](testing.md)
