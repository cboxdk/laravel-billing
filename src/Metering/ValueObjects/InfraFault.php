<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

use Cbox\Billing\Metering\Enums\OutcomeStatus;
use Throwable;

/**
 * Describes the infrastructure fault that made an enforcement decision
 * {@see OutcomeStatus::Indeterminate} — a store/cache
 * outage, a lock/lease timeout, or a transport error surfaced by a dependency. It is
 * NOT a semantic refusal: a fault means the decision could not be reached, not that
 * the request was authoritatively rejected.
 *
 * Immutable. `cause` retains the originating throwable for telemetry; `reason` is a
 * short operator-facing summary.
 */
readonly class InfraFault
{
    public function __construct(
        public string $reason,
        public ?Throwable $cause = null,
    ) {}

    /** Build a fault from the throwable a dependency raised on the hot path. */
    public static function from(Throwable $cause): self
    {
        $message = $cause->getMessage();
        $reason = $message === '' ? $cause::class : $cause::class.': '.$message;

        return new self($reason, $cause);
    }
}
