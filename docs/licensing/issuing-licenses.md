---
title: Issuing licenses
description: Resolve a licensable plan to a profile (deny-by-default), mint a signed license with LicenseMint, and persist the IssuedLicense record through the store.
weight: 10
---

# Issuing licenses

## Profiles and the resolver

A `LicenseProfile` is the issuer-side policy for one licensable plan: the plan id
carried into the license, the opaque **entitlement** strings it unlocks (spell them
with `Cbox\License\Capabilities` so both sides agree), and the quantitative
`LicenseLimits` it ceilings. A `null` limit dimension means *unlimited* for that
dimension.

`LicenseProfileResolver` maps a plan / product id to its profile. The shipped
`ConfiguredLicenseProfileResolver` resolves against a fixed map built from
`billing.licensing.profiles`:

```php
'licensing' => [
    'profiles' => [
        'enterprise' => [
            'entitlements' => [Capabilities::SSO, Capabilities::MULTI_TENANT_PLATFORM],
            'limits' => ['organizations' => 25, 'seats' => 500, 'environments' => 5],
        ],
    ],
],
```

**Deny-by-default falls straight out of the lookup:** a plan absent from the map
resolves to `null`, and a `null` profile cannot be minted. The default map is empty
— nothing is licensable until you declare a profile. A self-serve plan that ships no
offline artifact simply has no entry.

```php
$profile = app(LicenseProfileResolver::class)->resolve($planId);

if ($profile === null) {
    // Not a licensable plan — nothing to mint.
    return;
}
```

## Minting

`LicenseMint` turns a resolved profile plus the per-issue facts into a signed
license. It depends on the crypto core's `LicenseIssuer` contract — **the host binds
that with the private key** (see
[contracts & bindings](../extension-points/contracts-and-bindings.md)); this module
is key-agnostic.

```php
use Cbox\Billing\Licensing\LicenseMint;
use Cbox\Billing\Licensing\ValueObjects\LicenseIssuanceRequest;

$issued = app(LicenseMint::class)->issue(
    new LicenseIssuanceRequest(
        customerId: 'cus_acme',
        deploymentId: 'dep_acme_prod',
        profile: $profile,
        notBefore: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        expiresAt: new DateTimeImmutable('2027-01-01T00:00:00Z'),
        licensedDomain: 'acme.example', // optional domain pin
    ),
    issuedAt: new DateTimeImmutable(),
);

$issued->key; // the compact, signed EdDSA JWT to deliver to the deployment
$issued->id;  // equals the artifact's `lid` claim
```

`issuedAt` is passed in explicitly (it becomes the `iat` claim) so issuance is
deterministic and testable. The mint **pins the license id itself** rather than
letting the core generate one, so the record's `id` and the artifact's `lid` claim
always agree — that linkage is what makes [revocation by
id](renewal-and-revocation.md#revocation) possible. Supply
`LicenseIssuanceRequest::$licenseId` to use your own id instead.

## The `IssuedLicense` record and the store

`issue()` returns an `IssuedLicense`: the signed `->key` plus a decoded copy of
everything that went into it (`customerId`, `deploymentId`, `plan`, `entitlements`,
`limits`, `issuedAt`, `notBefore`, `expiresAt`, and the optional `licensedDomain`),
so the issuer side can list, renew, and revoke without re-parsing the JWT.

Persist it through `IssuedLicenseStore`:

```php
$store = app(IssuedLicenseStore::class);
$store->save($issued);

$store->find($issued->id);              // by license id
$store->forCustomer('cus_acme');        // list<IssuedLicense>
$store->forDeployment('dep_acme_prod'); // the deployment's current license, or null
```

A deployment holds at most one live license, so `save()` makes the newest license
the deployment's current one; a [renewal](renewal-and-revocation.md#renewal)
supersedes the prior one under the same deployment id, while both stay findable by
their own id. The default `InMemoryIssuedLicenseStore` is zero-config and not
durable; a host binds a connection-backed store implementing the same contract for
production (see [storage adapters](../extension-points/storage-adapters.md)).
