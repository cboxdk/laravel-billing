<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\ValueObjects;

/**
 * A merchant-configured reason a subscriber may pick when cancelling — the choices the
 * cancel flow offers ("too expensive", "missing a feature", "switching provider", …).
 * Immutable and UI-free: the engine carries the reason, the host renders it.
 *
 * `$requiresComment` marks a reason that only makes sense with free text (e.g. an
 * "other" bucket), so the host knows to require the {@see CancellationResponse::$comment}
 * before accepting the response.
 */
readonly class CancellationReason
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $requiresComment = false,
    ) {}
}
