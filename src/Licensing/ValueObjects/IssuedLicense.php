<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\ValueObjects;

use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\Billing\Licensing\LicenseMint;
use Cbox\License\ValueObjects\LicenseLimits;
use DateTimeImmutable;

/**
 * The persisted record of a minted license: the signed `$key` artifact plus a
 * decoded copy of everything that went into it, so the issuer side can list, renew
 * and revoke without re-parsing the JWT.
 *
 * The `$id` equals the artifact's `lid` claim (the mint pins them together), which is
 * what {@see RevocationRegistry::revoke()} keys on.
 * `$licensedDomain` is retained (beyond the minimal record) so a renewal
 * ({@see LicenseMint::reissue()}) can re-pin the SAME domain
 * binding rather than silently dropping it.
 */
readonly class IssuedLicense
{
    /**
     * @param  list<string>  $entitlements
     */
    public function __construct(
        public string $id,
        public string $key,
        public string $customerId,
        public string $deploymentId,
        public string $plan,
        public array $entitlements,
        public LicenseLimits $limits,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $expiresAt,
        public ?string $licensedDomain = null,
    ) {}
}
