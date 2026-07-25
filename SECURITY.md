# Security Policy

Cbox Billing is the billing engine that other applications embed to move money,
meter usage, and enforce spend limits. A correctness bug here is a security bug, so
we take reports seriously.

This policy covers the `cboxdk/laravel-billing` package. Vulnerabilities in the
deployable application belong in the `cboxdk/cbox-billing` repository instead;
issues in a payment-gateway adapter belong in that adapter's repository
(`cboxdk/laravel-billing-stripe`, `cboxdk/laravel-billing-mollie`).

## Reporting a vulnerability

Please report suspected vulnerabilities through **GitHub's Private Vulnerability
Reporting**:

1. Go to the repository's **Security** tab.
2. Choose **Report a vulnerability** to open a private advisory.

This keeps the report confidential between you and the maintainers until a fix is
available. Please do **not** open a public issue for a security problem — including
for the correctness bugs described below, which are exactly the kind of finding that
should not be public before a fix ships.

When you report, include what you'd want if you were fixing it: affected
version/commit, a description of the impact, and the steps or a proof-of-concept to
reproduce.

## What counts as a vulnerability in an engine

Because this package is a library rather than a deployed service, the highest-value
reports are **invariant violations** rather than classic web bugs:

- A path that lets the ledger double-spend, double-credit, or lose an entry.
- An idempotency bypass — the same logical operation applied twice.
- Proration, refund, or currency arithmetic that produces a wrong `Money` value.
- An enforcement decision that fails **open** where the contract says it must fail
  closed on a semantic denial.
- A way for one tenant's state to influence another's through engine APIs.

## What to expect

This is a small, actively developed project. We handle reports on a **best-effort**
basis — we don't publish a guaranteed response-time or remediation SLA, and we'd
rather set no promise than one we can't keep. We'll acknowledge valid reports, work
with you on a fix, and credit you if you'd like once any fix is released.

We don't currently operate a bug-bounty program, and we don't claim any security
certification, conformance, or audit that has not been performed.

## Supported versions

The package is **pre-1.0**. Fixes land on the latest `main`; we don't backport to
older tags. Run a current checkout.

## Scope and honest limits

The engine is **tamper-evident, not tamper-proof**, and it does not hash-chain or
sign ledger entries — a privileged operator with database access can alter history.
Host-layer controls (authentication, authorization, transport security, audit
chaining, rate limiting) are the embedding application's responsibility and are not
shipped here. See [`docs/security/threat-model.md`](docs/security/threat-model.md)
for the full statement of what this package does and does not defend against, and
[`adr/`](adr/) for the decision records behind each upheld invariant.

## Supply chain

The supply chain is gated in CI — `composer license-check` (permissive-only),
`composer audit`, and a drift-checked CycloneDX SBOM.
