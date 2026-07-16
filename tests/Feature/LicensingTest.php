<?php

declare(strict_types=1);

use Cbox\Billing\Licensing\ConfiguredLicenseProfileResolver;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\Contracts\LicenseProfileResolver;
use Cbox\Billing\Licensing\ValueObjects\LicenseIssuanceRequest;
use Cbox\Billing\Licensing\ValueObjects\LicenseProfile;
use Cbox\License\Capabilities;
use Cbox\License\Enums\LicenseStatus;
use Cbox\License\ValueObjects\LicenseLimits;
use Cbox\License\ValueObjects\RevocationList;
use Cbox\License\ValueObjects\VerificationContext;

beforeEach(function () {
    $this->profile = new LicenseProfile(
        plan: 'enterprise',
        entitlements: [Capabilities::SSO, Capabilities::MULTI_TENANT_PLATFORM],
        limits: new LicenseLimits(organizations: 25, seats: 500, environments: 5),
    );

    $this->request = new LicenseIssuanceRequest(
        customerId: 'cus_acme',
        deploymentId: 'dep_acme_prod',
        profile: $this->profile,
        notBefore: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        expiresAt: new DateTimeImmutable('2027-01-01T00:00:00Z'),
        licensedDomain: 'acme.example',
    );
});

it('resolves a licensable plan and denies an unknown plan (deny-by-default)', function () {
    $resolver = new ConfiguredLicenseProfileResolver(['enterprise' => $this->profile]);

    expect($resolver->resolve('enterprise'))->toBe($this->profile)
        ->and($resolver->resolve('self-serve'))->toBeNull();
});

it('resolves the configured profile resolver as empty (deny-by-default) by default', function () {
    $resolver = $this->app->make(LicenseProfileResolver::class);

    expect($resolver)->toBeInstanceOf(ConfiguredLicenseProfileResolver::class)
        ->and($resolver->resolve('enterprise'))->toBeNull();
});

it('mints a license whose key verifies against the deployment binding with the exact entitlements and limits', function () {
    $issued = $this->licenseMint()->issue($this->request, new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $result = $this->licenseVerifier()->verify(
        $issued->key,
        new VerificationContext('dep_acme_prod', 'acme.example', new DateTimeImmutable('2026-06-01T00:00:00Z')),
    );

    expect($result->status)->toBe(LicenseStatus::Valid)
        ->and($result->isLicensed())->toBeTrue()
        ->and($result->entitlements())->toBe([Capabilities::SSO, Capabilities::MULTI_TENANT_PLATFORM])
        ->and($result->limits()?->toArray())->toBe($this->profile->limits->toArray())
        ->and($issued->id)->toBe($result->license?->id);

    // The binding is real: a different deployment id is refused on the same key.
    $mismatch = $this->licenseVerifier()->verify(
        $issued->key,
        new VerificationContext('dep_other', 'acme.example', new DateTimeImmutable('2026-06-01T00:00:00Z')),
    );

    expect($mismatch->status)->toBe(LicenseStatus::BindingMismatch);
});

it('reissues for the same deployment with a later window that verifies after the original had expired', function () {
    $mint = $this->licenseMint();
    $original = $mint->issue($this->request, new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $afterOriginalExpiry = new DateTimeImmutable('2027-06-01T00:00:00Z');
    $context = new VerificationContext('dep_acme_prod', 'acme.example', $afterOriginalExpiry);

    // The original has expired by this date.
    expect($this->licenseVerifier()->verify($original->key, $context)->status)->toBe(LicenseStatus::Expired);

    $renewed = $mint->reissue($original, new DateTimeImmutable('2028-01-01T00:00:00Z'), new DateTimeImmutable('2027-01-01T00:00:00Z'));

    // Same binding and profile, fresh id, and valid at a date the original had expired.
    $result = $this->licenseVerifier()->verify($renewed->key, $context);

    expect($result->status)->toBe(LicenseStatus::Valid)
        ->and($renewed->deploymentId)->toBe($original->deploymentId)
        ->and($renewed->plan)->toBe($original->plan)
        ->and($renewed->id)->not->toBe($original->id)
        ->and($result->entitlements())->toBe([Capabilities::SSO, Capabilities::MULTI_TENANT_PLATFORM]);
});

it('publishes a signed revocation list that reports a revoked license as Revoked', function () {
    $issued = $this->licenseMint()->issue($this->request, new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $this->revocationRegistry()->revoke($issued->id);

    $signedList = $this->revocationPublisher()->currentList(new DateTimeImmutable('2026-06-01T00:00:00Z'));
    $revocations = RevocationList::fromSigned($signedList, $this->licensingKeyPair()['publicKey']);

    expect($revocations)->not->toBeNull();

    $result = $this->licenseVerifier()->verify(
        $issued->key,
        new VerificationContext('dep_acme_prod', 'acme.example', new DateTimeImmutable('2026-06-01T00:00:00Z'), $revocations),
    );

    expect($result->status)->toBe(LicenseStatus::Revoked);
});

it('round-trips an issued license through the store by id, customer and deployment', function () {
    $issued = $this->licenseMint()->issue($this->request, new DateTimeImmutable('2026-01-01T00:00:00Z'));

    $store = $this->app->make(IssuedLicenseStore::class);
    $store->save($issued);

    expect($store->find($issued->id))->toBe($issued)
        ->and($store->find('lic_missing'))->toBeNull()
        ->and($store->forCustomer('cus_acme'))->toBe([$issued])
        ->and($store->forCustomer('cus_nobody'))->toBe([])
        ->and($store->forDeployment('dep_acme_prod'))->toBe($issued)
        ->and($store->forDeployment('dep_unknown'))->toBeNull();
});
