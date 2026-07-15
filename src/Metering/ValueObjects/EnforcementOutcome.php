<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

use Cbox\Billing\Metering\Enums\DenialReason;
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;
use Cbox\Billing\Metering\Enums\OutcomeStatus;
use LogicException;

/**
 * The richer surface of an enforcement call (ADR-0004): a three-way outcome that
 * makes visible to callers and telemetry WHICH path fired, rather than collapsing
 * everything into a boolean or a thrown exception.
 *
 *  - `Allowed`       — carries the held {@see Reservation}/{@see ReservationSet}.
 *  - `Denied`        — carries the SEMANTIC {@see DenialReason} (fail-closed).
 *  - `Indeterminate` — carries the {@see InfraFault} that blocked a decision plus the
 *                      {@see InfraFailurePolicy} that resolved it. There is no
 *                      reservation: the dependency needed to hold one was down, so an
 *                      admitted (fail-open) request proceeds best-effort and is
 *                      reconciled from the durable ledger.
 *
 * `org` and `meters` are retained purely so a single outcome object is a complete
 * telemetry record. Immutable — build through the named constructors.
 */
readonly class EnforcementOutcome
{
    /**
     * @param  list<string>  $meters
     */
    private function __construct(
        public OutcomeStatus $status,
        public string $org,
        public array $meters,
        public Reservation|ReservationSet|null $value = null,
        public ?DenialReason $reason = null,
        public ?InfraFault $fault = null,
        public ?InfraFailurePolicy $resolvedBy = null,
    ) {}

    /**
     * @param  list<string>  $meters
     */
    public static function allowed(string $org, array $meters, Reservation|ReservationSet $value): self
    {
        return new self(OutcomeStatus::Allowed, $org, $meters, value: $value);
    }

    /**
     * @param  list<string>  $meters
     */
    public static function denied(string $org, array $meters, DenialReason $reason): self
    {
        return new self(OutcomeStatus::Denied, $org, $meters, reason: $reason);
    }

    /**
     * @param  list<string>  $meters
     */
    public static function indeterminate(string $org, array $meters, InfraFault $fault, InfraFailurePolicy $resolvedBy): self
    {
        return new self(OutcomeStatus::Indeterminate, $org, $meters, fault: $fault, resolvedBy: $resolvedBy);
    }

    /**
     * Should the caller proceed? True for a reached `Allowed`, and for an
     * `Indeterminate` the infra policy resolved to fail **open**.
     */
    public function admitted(): bool
    {
        return $this->status === OutcomeStatus::Allowed
            || ($this->status === OutcomeStatus::Indeterminate && $this->resolvedBy === InfraFailurePolicy::Allow);
    }

    /** The inverse of {@see admitted()} — the request must not proceed. */
    public function refused(): bool
    {
        return ! $this->admitted();
    }

    /**
     * True only when an infrastructure fault was admitted by the fail-open policy —
     * the exact condition operators must be signalled about.
     */
    public function failedOpen(): bool
    {
        return $this->status === OutcomeStatus::Indeterminate
            && $this->resolvedBy === InfraFailurePolicy::Allow;
    }

    /** The held single-meter reservation. Only present on a reached `Allowed`. */
    public function reservation(): Reservation
    {
        if (! $this->value instanceof Reservation) {
            throw new LogicException('This outcome holds no single-meter reservation.');
        }

        return $this->value;
    }

    /** The held bucket set. Only present on a reached `Allowed` from the bucket path. */
    public function reservationSet(): ReservationSet
    {
        if (! $this->value instanceof ReservationSet) {
            throw new LogicException('This outcome holds no reserved bucket set.');
        }

        return $this->value;
    }
}
