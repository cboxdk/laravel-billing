# ADR-0005 — Multi-dimensional metering with isolated allowances

**Status:** Accepted (2026-07-15)

## Context

Usage is rarely a single scalar. One request can consume several **metered dimensions** at
once (e.g. a base operation plus per-result field appends plus a separate weighted operation),
each with its own price weight. Some dimensions carry **isolated allowances** that must not be
funded from a shared pool. Some entitlements are **unlimited** (no cost), and a **disabled**
feature must be blocked *before* any allowance/cost math — otherwise an under-allowance call to
a disabled feature computes zero overage and runs for free.

A single "requests this period" counter cannot express weighting, isolation, unlimited, or
per-dimension entitlement.

## Decision

Meter usage as **independent buckets**. Each `(org, meter)` carries its own:

- **entitlement** (`enabled?`) — checked **first**; disabled → refuse before allowance/cost.
- **allowance** — included units per period; isolated allowances are **excluded from any shared
  basis** and never draw from another meter's pool.
- **weight / multiplier** — cost contribution per unit. `unlimited` zeroes cost **explicitly**;
  there is no implicit default multiplier (a `null` multiplier must not fall back to `1.0` and
  invent phantom cost).
- **overage behaviour** — per bucket (see ADR-0004 for the unknown-behaviour = fail-closed rule).

Buckets are evaluated **independently and never collapsed** into a single number. Total cost is
the sum of per-bucket weighted usage.

## Consequences

- `Enforcement` and `Entitlement` contracts key on `meter`; a request reserves/commits a *set*
  of bucket amounts, not one scalar.
- Cost calculation is per-bucket → summed; isolation and weighting are first-class.
- Included allowances that must be consumed exactly once under concurrency use an atomic
  claim (tracked as a follow-on to this ADR).

## Alternatives considered

- **Single scalar usage counter.** Rejected — cannot express weighting, isolation, unlimited,
  or per-dimension entitlement.
- **Collapse buckets to a normalized "credits" unit up front.** Rejected — loses per-dimension
  allowances and entitlement gating; a disabled dimension would still consume shared allowance.
