# ADR-0004 — Enforcement fails open on infrastructure, closed on semantics

**Status:** Accepted (2026-07-15)

## Context

Deny-by-default is the right stance for **authorization and semantics** — an unknown scope, a
disabled feature, or a missing entitlement must be refused, never silently trusted. But applied
uniformly it is wrong for **infrastructure**: if a cache or database hiccup makes the enforcer
throw and we deny, a dependency blip becomes a customer-facing outage on the hot path.

The two failure modes have opposite correct answers:

- Fail **closed** on a *semantic* unknown (unrecognized/`null` overage behaviour, disabled
  feature, missing entitlement) → otherwise usage runs for free.
- Fail **open** on an *infrastructure* error (store unavailable, lock timeout) → otherwise
  every Redis/DB blip throttles legitimate paid traffic.

## Decision

Enforcement returns a three-way outcome, and the failure policy is split by cause:

- `Allowed` / `Denied(reason)` — a real decision was reached.
- `Indeterminate(infra)` — a dependency was unavailable. Default policy: **allow + report**
  (configurable per deployment), preserving availability; the durable ledger remains the
  eventual authority and reconciliation (ADR-0003) recovers the truth.

Semantic unknowns always resolve to `Denied`, never `Indeterminate`.

## Consequences

- The `Enforcement` contract surfaces the outcome type so callers and telemetry can see which
  path fired, and operators get a signal when fail-open triggers.
- A configuration knob controls infra behaviour (`allow` default, `deny` for strict tenants).
- Complements ADR-0008: because balance is derived and the ledger is authoritative, a fail-open
  admission is reconciled, not lost.

## Alternatives considered

- **Uniform deny-by-default.** Rejected — turns infra blips into outages.
- **Uniform allow on error.** Rejected — a missing entitlement row would serve every org for free.
- **Retry-then-deny.** Rejected as the default — adds hot-path latency; retries belong in the
  store client, and the availability call still has to be made.
