<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\ValueObjects;

use Cbox\Billing\Licensing\LicenseMint;
use DateTimeImmutable;

/**
 * The input to {@see LicenseMint::issue()} — everything the
 * mint needs to turn a resolved {@see LicenseProfile} into one signed license for a
 * specific customer and deployment.
 *
 * The plan, entitlements and limits ride on the `$profile`; this request adds the
 * per-issue facts: who it is for (`$customerId`), which self-hosted deployment it is
 * bound to (`$deploymentId`), its validity window (`$notBefore` / `$expiresAt`), an
 * optional domain pin (`$licensedDomain`), and an optional caller-supplied id
 * (`$licenseId`; when omitted the mint generates one so the persisted record's id and
 * the artifact's `lid` claim always agree — that linkage is what makes revocation by
 * id possible).
 */
readonly class LicenseIssuanceRequest
{
    public function __construct(
        public string $customerId,
        public string $deploymentId,
        public LicenseProfile $profile,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $expiresAt,
        public ?string $licensedDomain = null,
        public ?string $licenseId = null,
    ) {}
}
