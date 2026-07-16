<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\Contracts;

use Cbox\Billing\Licensing\RevocationPublisher;

/**
 * The issuer-side set of revoked license ids. {@see RevocationPublisher}
 * reads it to cut a freshly-signed revocation list; a verifier deployment then
 * consults that list as the last step of verification.
 *
 * Revoking is idempotent — revoking an already-revoked id is a no-op — and
 * {@see revokedIds()} returns the current set as a plain `list<string>`.
 */
interface RevocationRegistry
{
    public function revoke(string $licenseId): void;

    /**
     * @return list<string>
     */
    public function revokedIds(): array;
}
