# ADR-0017 — The engine supports a single Laravel major

**Status:** Accepted

## Context

The house standard for `cboxdk/*` framework libraries is to support the **current
and previous** Laravel major (`illuminate/*: "^12.0 || ^13.0"`), so a library
installs into any app that is at most one major behind.

This engine does not meet that rule and never has: every published release from
`v0.5.0` through `v0.8.1` requires `illuminate/* ^13.0`. A platform review surfaced
the resulting inconsistency — five commercial plugins declared `^12.0 || ^13.0`
while depending on this engine, so their Laravel 12 support was **unresolvable in
practice**. Two of them additionally carried a Testbench `10.*` CI leg that could
never install, leaving those matrices permanently red and training the team to
ignore a failing build.

The disagreement had to be resolved in one direction or the other. Widening the
engine to Laravel 12 is not a constraint edit — it requires a genuine compatibility
pass and a doubled test matrix, carried indefinitely.

## Decision

**The platform targets Laravel 13 only.** The engine keeps `illuminate/* ^13.0`,
and every package that depends on it declares the same. The plugins' `^12.0`
claims are removed rather than the engine's pin being widened.

This is a **deliberate, recorded deviation** from the current-and-previous-major
rule, not an oversight.

Rationale: the deployable application (`cboxdk/cbox-billing`) is the primary
consumer and tracks the current major by policy. The engine's remaining consumers
are first-party plugins that ship in lockstep with it. Carrying a second Laravel
major would cost a permanent doubling of the compatibility surface for an audience
that does not currently exist — and a support claim we were not in fact honouring
is worse than an honest, narrower one.

## Consequences

- A Laravel 12 application cannot install this engine. That is now stated plainly
  in `docs/requirements.md` rather than implied otherwise by plugin metadata.
- CI matrices carry a single Testbench major (`11.*`). **Do not re-add a `10.*`
  leg** — it cannot resolve, and a red leg that is expected to be red is worse
  than no leg at all.
- When Laravel 14 lands, the decision is revisited rather than applied by reflex:
  the choice then is to move to `^14.0` only, or to adopt the standard
  current-and-previous window at that point. Widening at a major boundary is
  cheaper than widening retroactively.
- A reviewer measuring this repository against the house standard will flag the
  single-major pin. This ADR is the answer: it is intentional, and changing it is
  a product decision, not a lint fix.
