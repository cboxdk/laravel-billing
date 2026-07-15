# ADR-0011 — Credit consequences of plan change and cancellation

**Status:** Accepted (2026-07-16)

## Context

When a subscription changes plan or cancels, the org's credits must resolve
deterministically, and the customer must see the consequence **before** confirming.
ADR-0001 gives pools a behaviour matrix (`forfeitsOnCancel`, `requiresExpiry`,
`mayGoNegative`, `spendable`); ADR-0006 forfeits `forfeitsOnCancel` pools on a
"left-without-landing" transition (cancel-to-null). Two cases are still undefined:

- a **plan switch** to another active plan — the outgoing plan's unspent recurring
  allotment currently just stays in the wallet (never forfeited, no new-plan reset);
- **cancel-at-period-end** timing — forfeiture must fire at period end, not immediately.

## Decision

A deterministic **credit-consequence policy** per transition, expressed through the pool
matrix, and surfaced in the preview.

**Pool rules are the invariant (ADR-0001):**
- `purchased` / pay-as-you-go (never forfeits) → **always carries over** across any switch
  or cancel.
- `promotional` / `regulated` (`requiresExpiry`) → follow their **own expiry timer**,
  unaffected by the transition (a switch neither extends nor shortens them).
- `included` / subscription allotment (`forfeitsOnCancel`) → governed by the rule below.

**Plan switch (A→B, both active):** by default the **outgoing recurring allotment is
forfeited** and the **incoming plan's allotment granted** (a clean per-cycle reset, floored
at 0 so it never offsets a negative pay-as-you-go pool). A family/edge (ADR-0010) MAY
declare `carryOver` to keep the unspent outgoing allotment instead (e.g. an in-family
upgrade). Deny-by-default is **forfeit-and-regrant**; carry-over is opt-in per edge.

**Cancel:**
- **immediate** → forfeiture fires now (ADR-0006), access ends now; `purchased` survives and
  any negative pay-as-you-go pool settles on the final invoice.
- **at period end** → access + credits remain until period end; the forfeiture transition is
  **scheduled** to fire at period end, not at request time.

**The preview shows the credit consequence.** The plan-change / cancel preview returns,
alongside the money (ADR-0007), a **credit delta**: units forfeited, units granted, units
carrying over, and any pool left negative — so the confirm step is operationally honest
("you'll lose N included credits; your M purchased credits carry over").

## Consequences

- The lifecycle forfeiture handler runs on plan-switch transitions too (not only
  cancel-to-null), honouring an optional per-edge `carryOver`.
- The previewer computes the credit delta next to the money delta (one function, ADR-0007).
- Scheduled at-period-end cancel defers forfeiture to period end.
- The management API / SDK / hosted portal show the credit consequence before confirm.

## Alternatives considered

- **Carry-over by default.** Rejected — unbounded credit accumulation across repeated
  switches misprices; forfeit-and-regrant is the clean default, carry-over a deliberate
  per-edge choice.
- **Silent behaviour.** Rejected — the customer must see what they lose/keep before
  confirming; matches the operationally-honest ethos.
