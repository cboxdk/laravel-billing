<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing;

use Cbox\Billing\Events\LicenseIssued;
use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Cbox\Billing\Licensing\ValueObjects\LicenseIssuanceRequest;
use Cbox\Billing\Licensing\ValueObjects\LicenseProfile;
use Cbox\License\Contracts\LicenseIssuer;
use Cbox\License\ValueObjects\LicenseRequest;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Mints signed licenses from a resolved plan {@see LicenseProfile}. This is the
 * issuer-side entry point: it turns billing's own request into the crypto core's
 * {@see LicenseRequest}, signs it via the injected {@see LicenseIssuer}, and returns
 * the persistable {@see IssuedLicense} record (the signed artifact plus a decoded
 * copy of its contents).
 *
 * The mint is **key-agnostic**: it depends on the {@see LicenseIssuer} contract, which
 * the host constructs from config holding the private key and binds in the container.
 * This module never sees or stores the key.
 *
 * The mint pins the license id itself (rather than letting the core generate one),
 * so the returned record's id and the artifact's `lid` claim always agree — the
 * linkage {@see RevocationRegistry} relies on to
 * revoke a specific license.
 */
readonly class LicenseMint
{
    public function __construct(
        private LicenseIssuer $issuer,
        private ?Dispatcher $events = null,
    ) {}

    /**
     * Sign one license for the request as of `$issuedAt` (the `iat` claim) and return
     * the record. A caller-supplied `$request->licenseId` is honoured; otherwise a
     * fresh id is generated.
     */
    public function issue(LicenseIssuanceRequest $request, DateTimeImmutable $issuedAt): IssuedLicense
    {
        $profile = $request->profile;
        $id = $request->licenseId ?? self::generateId();

        $key = $this->issuer->issue(new LicenseRequest(
            plan: $profile->plan,
            entitlements: $profile->entitlements,
            limits: $profile->limits,
            customerId: $request->customerId,
            deploymentId: $request->deploymentId,
            licensedDomain: $request->licensedDomain,
            issuedAt: $issuedAt,
            notBefore: $request->notBefore,
            expiresAt: $request->expiresAt,
            id: $id,
        ));

        $license = new IssuedLicense(
            id: $id,
            key: $key,
            customerId: $request->customerId,
            deploymentId: $request->deploymentId,
            plan: $profile->plan,
            entitlements: $profile->entitlements,
            limits: $profile->limits,
            issuedAt: $issuedAt,
            notBefore: $request->notBefore,
            expiresAt: $request->expiresAt,
            licensedDomain: $request->licensedDomain,
        );

        // Fires for every mint, including a reissue() (which runs through issue()) — each
        // reissue is a distinct, independently-revocable license and merits its own event.
        $this->events?->dispatch(new LicenseIssued($license));

        return $license;
    }

    /**
     * Re-mint for the SAME deployment, customer and profile with an extended validity
     * window (a subscription renewal): the plan, entitlements, limits, deployment,
     * customer and domain binding are carried over from `$existing`, `notBefore` is
     * preserved, `expiresAt` moves out to `$newExpiresAt`, and a fresh id is drawn so
     * the renewed artifact is a distinct, independently-revocable license.
     */
    public function reissue(IssuedLicense $existing, DateTimeImmutable $newExpiresAt, DateTimeImmutable $issuedAt): IssuedLicense
    {
        return $this->issue(new LicenseIssuanceRequest(
            customerId: $existing->customerId,
            deploymentId: $existing->deploymentId,
            profile: new LicenseProfile($existing->plan, $existing->entitlements, $existing->limits),
            notBefore: $existing->notBefore,
            expiresAt: $newExpiresAt,
            licensedDomain: $existing->licensedDomain,
            licenseId: null,
        ), $issuedAt);
    }

    private static function generateId(): string
    {
        return 'lic_'.bin2hex(random_bytes(16));
    }
}
