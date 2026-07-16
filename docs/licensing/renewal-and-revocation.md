---
title: Renewal & revocation
description: Re-mint a license on subscription renewal with reissue(), track the paid window with SubscriptionLicensePolicy, publish a signed revocation list, and verify what you minted.
weight: 20
---

# Renewal & revocation

## Renewal

A subscription renewal extends the paid window, so the license must be re-minted to
match. `LicenseMint::reissue()` re-mints for the **same** deployment, customer, and
profile with an extended window and a **fresh id**:

```php
$renewed = app(LicenseMint::class)->reissue(
    $existing,                                     // the prior IssuedLicense
    newExpiresAt: new DateTimeImmutable('2028-01-01T00:00:00Z'),
    issuedAt: new DateTimeImmutable(),
);

app(IssuedLicenseStore::class)->save($renewed);    // supersedes the deployment's current license
```

The plan, entitlements, limits, deployment, customer, and domain binding all carry
over from the prior record; `notBefore` is preserved and `expiresAt` moves out. The
new id makes the renewed artifact a **distinct, independently-revocable** license —
revoking last year's does not touch this year's.

### Tracking the paid period

`SubscriptionLicensePolicy` is a small pure helper that derives a license's
`expiresAt` from the subscription's current paid-period end, plus a configurable
grace buffer (`billing.licensing.grace_seconds`):

```php
$expiresAt = app(SubscriptionLicensePolicy::class)
    ->expiresAtFor($subscription->currentPeriodEnd());

$renewed = app(LicenseMint::class)->reissue($existing, $expiresAt, new DateTimeImmutable());
```

The grace buffer keeps the offline artifact valid slightly past the period boundary
— covering the lag between a renewal being paid and the new license being pulled by
the deployment — without letting the license outlive the paid period by more than
the buffer you chose. The helper only computes a date; **the app decides when to
renew** and calls `reissue()` with the result.

## Revocation

Revocation is issuer-side state plus a signed feed the verifier consults offline.
`RevocationRegistry` holds the revoked ids; `RevocationPublisher` cuts a freshly
signed revocation list from it:

```php
app(RevocationRegistry::class)->revoke($issued->id); // idempotent

$signedList = app(RevocationPublisher::class)
    ->currentList(new DateTimeImmutable());           // compact, signed EdDSA JWT
```

Deliver `$signedList` to deployments. Like the mint, the publisher is key-agnostic —
it signs with the host-bound `RevocationListIssuer` and the same private key used for
licenses. The default `InMemoryRevocationRegistry` is not durable; a host binds a
connection-backed registry so revocations survive a restart and are shared across
issuer nodes.

> The verifier side treats a missing or malformed revocation list as **fail-open**
> ("no revocations known") so a broken feed cannot brick a legitimately licensed
> deployment. That behaviour lives in `cboxdk/license`, on the verifier — not in this
> package.

## Verifying what you minted

Verification is **not this package's job** — it belongs to the self-hosted app, which
bundles the **public** key. But the same core verifier is what your issuer tests use
to prove a minted artifact reads back exactly as the deployment will see it:

```php
use Cbox\License\Ed25519LicenseVerifier;
use Cbox\License\ValueObjects\{RevocationList, VerificationContext};

$verifier = new Ed25519LicenseVerifier($publicKeyBase64);

$result = $verifier->verify($issued->key, new VerificationContext(
    deploymentId: 'dep_acme_prod',
    domain: 'acme.example',
    now: new DateTimeImmutable(),
    revocations: RevocationList::fromSigned($signedList, $publicKeyBase64),
));

$result->isLicensed();     // true only when Valid or InGrace
$result->entitlements();   // [] unless in force — deny-by-default
$result->status;           // Valid | Expired | Revoked | BindingMismatch | …
```

Entitlement gating and limit enforcement (reading `$result->entitlements()` /
`$result->limits()` and refusing features accordingly) are the **consuming app's**
responsibility. This package guarantees only that the artifact it produced is a
well-formed, correctly-bound, signed license.
