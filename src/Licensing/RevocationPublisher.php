<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing;

use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\License\Contracts\RevocationListIssuer;
use DateTimeImmutable;

/**
 * Cuts a freshly-signed revocation list from the current {@see RevocationRegistry}.
 * A verifier deployment fetches this artifact and consults it as the last step of
 * verification, so a revoked license is refused offline until the next list is
 * pulled.
 *
 * Like {@see LicenseMint}, this is key-agnostic — it depends on the crypto core's
 * {@see RevocationListIssuer} contract, which the host binds from config holding the
 * same private key used to sign licenses. The instant is passed in explicitly so the
 * list carries an honest "cut at" time and issuance stays deterministic.
 */
readonly class RevocationPublisher
{
    public function __construct(
        private RevocationListIssuer $issuer,
        private RevocationRegistry $registry,
    ) {}

    /**
     * The current revocation list, signed as of `$issuedAt`: every id the registry
     * holds, wrapped in a compact EdDSA JWT.
     */
    public function currentList(DateTimeImmutable $issuedAt): string
    {
        return $this->issuer->issue($this->registry->revokedIds(), $issuedAt);
    }
}
