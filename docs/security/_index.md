---
title: Security
description: What the library defends against, what it deliberately leaves to the app, and where the honest boundaries are.
weight: 80
---

# Security

Cbox Billing is a library of billing primitives, not a hosted service. Its security
posture is about **correctness invariants** — no double-billing, no double-spend, no
silent money loss — and about being **honest about where the library ends and your
application begins**.

## In this section

- [Threat model](threat-model.md) — the invariants the library upholds, the
  properties it does *not* provide, and the app-layer responsibilities it cannot
  cover.

## The one-line summary

The ledger is **tamper-evident, not tamper-proof**. The library makes the
money-correctness bugs (double-spend, double-post, over-refund, allowance re-grant)
unreachable by construction, and gives you an append-only, idempotent,
reconcilable record. It does **not** authenticate your users, encrypt your
database, verify payment webhooks for you (the adapter does), or stop a privileged
operator with database access. Those live in your application and your
infrastructure. See the [threat model](threat-model.md) for the honest breakdown.
