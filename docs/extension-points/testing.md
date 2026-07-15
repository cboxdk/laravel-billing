---
title: Testing
description: Every module ships a dogfooded InteractsWith* trait and Fake* doubles so you can drive it without infrastructure.
weight: 64
---

# Testing

Each module ships the same testing shape as the rest of the cbox ecosystem: an
`InteractsWith*` trait that wires the in-memory bindings, plus `Fake*` doubles for
the ports you would otherwise have to stand up. These are shipped in the package
(under each module's `Testing/` namespace), not test-only fixtures.

## Traits and doubles by module

| Module | Trait | Notable fakes |
| --- | --- | --- |
| Metering | `InteractsWithMetering` | `FakeAllowanceLeaseSource`, `FakeMeterPolicyResolver`, `OutageLocalStore`, `RecordingEnforcementSignals` |
| Wallet | `InteractsWithWallet` | — |
| Ledger | `InteractsWithLedger` | — |
| Reconciliation | `InteractsWithReconciliation` | `FakeCheckpointStore` |
| Subscription | `InteractsWithSubscriptionLifecycle` | `FakeForfeitureHandler` |
| Entitlement (audit) | `InteractsWithEntitlementAudit` | `FakeEntitlementAudit`, `RecordingEntitlementAuditSignals` |
| Entitlement (rollout) | `InteractsWithEntitlementRollout` | `FakeEntitlementRollout`, `FakeRolloutJournal`, `RecordingCacheInvalidator` |
| Entitlement | — | `FakeEntitlementWriter` |
| Account | `InteractsWithAccountStanding`, `InteractsWithBillingCurrencyLock` | `FakeAccountStanding`, `FakeBillingCurrencyLock` |
| Payment (dunning) | `InteractsWithDunning` | `FakeDunningStateStore`, `FakeDelinquentAllowList` |
| Payment (webhooks) | `InteractsWithWebhooks` | `FakePaymentGateway`, `FakeWebhookVerifier`, `FakeProcessedEventStore`, `FakeSettledPaymentStore`, `FakeInvoicePaymentApplier` |
| Refund | `InteractsWithRefunds` | `FakeRefunder`, `FakeChargebackHandler` |

## Driving a failure path

The metering fakes are built to exercise the hard cases directly. `makeEnforcement`
takes a custom `$store` and an `$infraPolicy`, the two knobs an ADR-0004 outcome
test needs — pass an `OutageLocalStore` to make the store throw and assert the
[three-way outcome](../core-concepts/metering.md#the-three-way-outcome) fails open
(or closed) as configured:

```php
use Cbox\Billing\Metering\Testing\{InteractsWithMetering, OutageLocalStore};
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;

$enforcement = $this->makeEnforcement(
    store: new OutageLocalStore(),        // the store throws → infra-failure path
    infraPolicy: InfraFailurePolicy::Allow,
);

$outcome = $enforcement->reserveOutcome($org, 'api.calls', 1);

$this->assertTrue($outcome->failedOpen()); // admitted under the allow policy
```

Use the recording signal fakes (`RecordingEnforcementSignals`,
`RecordingEntitlementAuditSignals`) to assert that operators would have been
signalled when a fail-open or an outage fired.

## Related

- [Contracts & bindings](contracts-and-bindings.md)
- [Metering & enforcement](../core-concepts/metering.md)
