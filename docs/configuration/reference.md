---
title: Configuration reference
description: Every config/billing.php key with its environment variable, default, and effect.
weight: 71
---

# Configuration reference

Every key in `config/billing.php`, grouped by section.

## `account`

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `currency_lock_store` | `CBOX_BILLING_CURRENCY_LOCK_STORE` | `memory` | Where the per-account billing-currency lock lives. `database` runs the migration; pair it with a durable invoice number sequence on the same connection so the first-finalize stamp and the invoice commit land together. |

The currency lock is keyed on the billing account alone and is independent of any
payment method — it survives a card being added or removed. See
[Accounts](../core-concepts/accounts.md).

## `payment.dunning`

Suspension gates **access only**; it never touches credit balances or the ledger.
Restore requires **all** debt cleared and none written off.

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `max_delinquency_days` | `CBOX_BILLING_DUNNING_MAX_DAYS` | `30` | Once the oldest past-due invoice is this old — and the minimum notices have gone out — the account escalates to suspension. |
| `min_notice_count` | `CBOX_BILLING_DUNNING_MIN_NOTICES` | `3` | How many reminders must be sent before suspension is allowed. An account is never suspended un-warned. |
| `notice_frequency_days` | `CBOX_BILLING_DUNNING_NOTICE_DAYS` | `7` | Minimum gap, in days, between two reminders. |
| `grace_hours` | `CBOX_BILLING_DUNNING_GRACE_HOURS` | `24` | An invoice fresher than this past its due instant is a just-missed payment and is not dunned. Governs notices/suspension only; any open invoice still counts as debt. |

See [Payments & dunning](../core-concepts/payments-and-dunning.md).

## `metering`

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `lease.default_size` | `CBOX_BILLING_LEASE_SIZE` | `100` | Units requested per lease refill. Larger = fewer round-trips but more units potentially stranded on a node. |
| `lease.prefix` | `CBOX_BILLING_LEASE_PREFIX` | `cbox-billing:lease:` | Cache-key prefix for leases. |
| `enforcement.infra_failure` | `CBOX_BILLING_INFRA_FAILURE` | `allow` | ADR-0004 policy for an *infrastructure* failure: `allow` fails open (a blip does not throttle paid traffic), `deny` fails closed for strict tenants. Semantic unknowns always fail closed regardless. |
| `dedup_window_days` | `CBOX_BILLING_DEDUP_WINDOW_DAYS` | `32` | How long a usage event's dedup key is kept so re-delivered events count once. Late duplicates outside the window are caught by reconciliation. |
| `event_log` | `CBOX_BILLING_EVENT_LOG` | `memory` | The immutable usage event log (metering source of truth). `memory` · `database` (run the migration) · a ClickHouse adapter for event-heavy scale. |

See [Metering & enforcement](../core-concepts/metering.md) and
[ADR-0004](../../adr/0004-enforcement-failure-policy.md).

## `entitlement.rollout`

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `chunk_size` | `CBOX_BILLING_ROLLOUT_CHUNK_SIZE` | `500` | Orgs written per transaction in a bulk rollout. Larger = fewer transactions but a bigger rollback unit and more rows locked at once. Orgs with overrides bypass this path. |

See [Entitlements](../core-concepts/entitlements.md).

## `reconciliation`

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `ingest_lag_seconds` | `CBOX_BILLING_RECONCILE_INGEST_LAG` | `60` | Usage is only reconciled up to `now − this`, so in-flight events are not counted early. Size to the async pipeline's worst-case landing delay. |
| `window_days` | `CBOX_BILLING_RECONCILE_WINDOW_DAYS` | `32` | Usage older than `now − this` is attributed to an `aged_out` bucket instead of the live meter — never dropped. |
| `currency` | `CBOX_BILLING_RECONCILE_CURRENCY` | `EUR` | The denomination usage deltas are carried into the ledger in (the allowance unit the derived balance reads, not a priced amount). Any currency the host ledger is registered for. |
| `checkpoint_store` | `CBOX_BILLING_RECONCILE_CHECKPOINT` | `memory` | Where per-entity checkpoints live. `database` runs the migration; pair it with a database ledger on the same connection so the delta post and checkpoint advance share one transaction. |

See [Reconciliation](../core-concepts/reconciliation.md) and
[ADR-0003](../../adr/0003-convergent-reconciliation.md).

## Related

- [Installation](../getting-started/installation.md)
- [Storage adapters](../extension-points/storage-adapters.md)
