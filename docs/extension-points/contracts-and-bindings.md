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
| `Licensing\Contracts\LicenseProfileResolver` | Licensing | `ConfiguredLicenseProfileResolver` (empty map, deny-by-default) |
| `Licensing\Contracts\IssuedLicenseStore` | Licensing | `InMemoryIssuedLicenseStore` |
| `Licensing\Contracts\RevocationRegistry` | Licensing | `InMemoryRevocationRegistry` |

## The unbound licensing key contracts

The licensing module deliberately leaves **two** crypto-core contracts unbound,
because they hold the issuer **private key** — a host secret, not a package default:

| Contract | Module | Default binding |
| --- | --- | --- |
| `Cbox\License\Contracts\LicenseIssuer` | Licensing | **none** — host binds `Ed25519LicenseIssuer($privateKey)` |
| `Cbox\License\Contracts\RevocationListIssuer` | Licensing | **none** — host binds `Ed25519RevocationListIssuer($privateKey)` |

`LicenseMint` and `RevocationPublisher` resolve these lazily, so resolving either one
before the host has bound the key surfaces a clear container error rather than minting
with no key:

```php
use Cbox\License\Contracts\LicenseIssuer;
use Cbox\License\Contracts\RevocationListIssuer;
use Cbox\License\Ed25519LicenseIssuer;
use Cbox\License\Ed25519RevocationListIssuer;

public function register(): void
{
    $this->app->singleton(LicenseIssuer::class, fn () => new Ed25519LicenseIssuer(config('services.licensing.private_key')));
    $this->app->singleton(RevocationListIssuer::class, fn () => new Ed25519RevocationListIssuer(config('services.licensing.private_key')));
}
```

See [Licensing](../licensing/_index.md).

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
