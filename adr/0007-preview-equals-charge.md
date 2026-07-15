# ADR-0007 — Preview equals charge; per-line rounding to the settlement gateway

**Status:** Accepted (2026-07-15)

## Context

A plan-change or renewal **preview** must equal what is actually **charged**, to the cent — it
is a promise shown to the customer at the confirm step. Two things break that promise:

- Computing preview and charge on **separate code paths** — they drift.
- Rounding a **combined total** — settlement gateways round each invoice line independently, so
  a single rounded total diverges from the gateway by a cent or two.

Proration itself is **anchor-dependent** and full of edges: keep-anchor prorates the delta to
renewal; reset-anchor charges a fresh period minus unused base and **can net a credit**;
deferred downgrades move no money; entering from pay-as-you-go charges a full fresh period with
no credit; the proration instant can precede the period start; a zero-length period must not
divide by zero.

## Decision

- **One function** computes the proration/quote. The **previewer and the charger both call it** —
  identical inputs yield an identical result *by construction*, not by parallel maintenance.
- **Round each line independently** to whole minor units, matching the settlement gateway's
  rounding, before summing.
- The calculator is explicit about **anchor** (keep vs reset), may **net a credit** on reset,
  **defers** downgrades, charges **full-fresh from PAYG**, **clamps** a pre-period instant, and
  handles a **zero-length period** without division by zero.

## Consequences

- `Quote` / `Proration` is the single source of truth; `PlanChangePreviewer` delegates to it and
  adds no arithmetic of its own.
- Preview↔charge parity is testable directly (same inputs → same object).
- Line-level rounding is a property of the quote, aligned to the gateway adapter in use.

## Alternatives considered

- **Separate preview and charge implementations.** Rejected — they drift; the preview becomes a
  lie.
- **Round the combined total.** Rejected — mismatches the gateway's per-line rounding.
