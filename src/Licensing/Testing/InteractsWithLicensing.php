<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\Testing;

use Cbox\Billing\Licensing\LicenseMint;
use Cbox\Billing\Licensing\RevocationPublisher;
use Cbox\Billing\Licensing\Storage\InMemoryIssuedLicenseStore;
use Cbox\Billing\Licensing\Storage\InMemoryRevocationRegistry;
use Cbox\License\Ed25519LicenseIssuer;
use Cbox\License\Ed25519LicenseVerifier;
use Cbox\License\Ed25519RevocationListIssuer;
use Cbox\License\Support\Ed25519KeyPair;

/**
 * Wires the real license issuer end-to-end in tests over a genuine Ed25519 keypair,
 * so the package dogfoods its own {@see LicenseMint} / {@see RevocationPublisher}
 * against the real crypto core rather than a mock:
 *
 *     $issued = $this->licenseMint()->issue($request, $issuedAt);
 *     $result = $this->licenseVerifier()->verify($issued->key, $context);
 *     expect($result->isLicensed())->toBeTrue();
 *
 * The mint and publisher sign with the private key; {@see licenseVerifier()} verifies
 * with the matching public key — proving what billing minted verifies exactly as the
 * self-hosted deployment will read it. The keypair, store and registry are created
 * once per test so a mint and a later verification share the same instances.
 *
 * `$graceSeconds` on {@see licenseVerifier()} lets a test opt into the verifier's
 * grace window; it defaults to a strict verifier (no grace).
 */
trait InteractsWithLicensing
{
    /** @var array{publicKey: string, privateKey: string}|null */
    private ?array $licensingKeyPair = null;

    private ?InMemoryIssuedLicenseStore $issuedLicenseStoreInstance = null;

    private ?InMemoryRevocationRegistry $revocationRegistryInstance = null;

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    protected function licensingKeyPair(): array
    {
        return $this->licensingKeyPair ??= Ed25519KeyPair::generate();
    }

    protected function licenseMint(): LicenseMint
    {
        return new LicenseMint(new Ed25519LicenseIssuer($this->licensingKeyPair()['privateKey']));
    }

    protected function revocationPublisher(): RevocationPublisher
    {
        return new RevocationPublisher(
            new Ed25519RevocationListIssuer($this->licensingKeyPair()['privateKey']),
            $this->revocationRegistry(),
        );
    }

    protected function licenseVerifier(int $graceSeconds = 0): Ed25519LicenseVerifier
    {
        return new Ed25519LicenseVerifier($this->licensingKeyPair()['publicKey'], $graceSeconds);
    }

    protected function issuedLicenseStore(): InMemoryIssuedLicenseStore
    {
        return $this->issuedLicenseStoreInstance ??= new InMemoryIssuedLicenseStore;
    }

    protected function revocationRegistry(): InMemoryRevocationRegistry
    {
        return $this->revocationRegistryInstance ??= new InMemoryRevocationRegistry;
    }
}
