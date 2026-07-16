---
title: Licensing (on-prem issuer)
description: The issuer side of on-prem / self-hosted licensing — mint a signed, offline-verifiable license from a licensable plan, renew it on subscription renewal, and revoke it. The verifier and entitlement-gating live in the consuming app.
weight: 55
---

# Licensing (on-prem issuer)

The licensing module is the **issuer** side of on-prem / self-hosted software
licensing. Billing mints a signed, offline-verifiable **license** from an
enterprise / on-prem plan; a self-hosted deployment verifies it offline and gates
its own features on the result.

This package ships **only the issuer**. It wraps the framework-agnostic crypto core
[`cboxdk/license`](https://github.com/cboxdk/license) (`Cbox\License\*`) — it does
not reimplement any crypto, and it never holds the signing key. The **verifier**
(`Ed25519LicenseVerifier`), the **entitlement gating**, and the **limit enforcement**
all live in the consuming app (the self-hosted deployment), not here. This module's
job stops at producing a signed artifact and the record of it.

## Mental model

```
plan id ──► LicenseProfileResolver ──► LicenseProfile ──► LicenseMint ──► IssuedLicense
                (deny-by-default)      (entitlements+limits)   │              (record + signed key)
                                                               ▼
                                                    Cbox\License\LicenseIssuer
                                                     (host binds it with the
                                                      PRIVATE key from config)
```

- A [**profile**](issuing-licenses.md#profiles-and-the-resolver) is what a
  licensable plan grants: a plan id, its entitlement strings, and its quantitative
  limits. An unknown or non-licensable plan resolves to `null` — **deny-by-default,
  it cannot be minted.**
- [`LicenseMint`](issuing-licenses.md) turns a resolved profile plus the per-issue
  facts (customer, deployment, window, optional domain) into an `IssuedLicense`:
  the signed artifact (`->key`) and a decoded record of its contents.
- On subscription renewal, [`reissue()`](renewal-and-revocation.md#renewal) re-mints
  the same deployment/profile with an extended window and a fresh id.
- [`RevocationPublisher`](renewal-and-revocation.md#revocation) cuts a signed
  revocation list from the issuer-side registry; a verifier consults it offline.

## The key boundary

The signing **private key never enters this module.** `LicenseMint` and
`RevocationPublisher` depend on the crypto core's `LicenseIssuer` /
`RevocationListIssuer` contracts; the **host** constructs the Ed25519
implementations from its own secret config and binds them in the container. The
`LicensingServiceProvider` deliberately leaves those two contracts unbound — see
[extension points](../extension-points/contracts-and-bindings.md).

## Pages

| Page | Covers |
| --- | --- |
| [Issuing licenses](issuing-licenses.md) | Profiles, the resolver, `LicenseMint::issue()`, the `IssuedLicense` record, the store |
| [Renewal & revocation](renewal-and-revocation.md) | `reissue()` on renewal, the subscription-window policy, `RevocationPublisher`, verifying what you minted |
