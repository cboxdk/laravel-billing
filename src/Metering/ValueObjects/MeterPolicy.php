<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\ValueObjects;

use Cbox\Billing\Metering\Enums\OverageBehaviour;

/**
 * The entitlement policy for one `(org, meter)` bucket. Every metered dimension is
 * an INDEPENDENT bucket carrying its own policy — buckets are evaluated separately
 * and never collapsed into a single number (ADR-0005).
 *
 * Fields:
 *  - `enabled`    — is the feature entitled at all? Checked FIRST; a disabled meter
 *                   is refused before any allowance/cost math (otherwise an
 *                   under-allowance call to a disabled feature computes zero overage
 *                   and runs for free).
 *  - `allowance`  — included units per period, ISOLATED: they are this bucket's own
 *                   pool and are never funded from, nor contributed to, another
 *                   meter's basis. Under ADR-0013 this is the balance of the meter's
 *                   recurring grant into the `included` pool — the allowance's HOME is
 *                   a pool grant so it can mix with credits in one burn-down; a
 *                   wallet-backed resolver fills it via {@see withAllowance()} rather
 *                   than the number being hand-authored. The isolation, weighting, and
 *                   disabled-first semantics of ADR-0005 are unchanged.
 *  - `multiplier` — cost contribution per billable (overage) unit. Deliberately
 *                   NULLABLE with NO default: an absent multiplier means "no cost
 *                   basis configured" and yields zero cost — there is no implicit
 *                   `?? 1.0` that would invent phantom cost.
 *  - `unlimited`  — the dimension has no cap and no cost; it zeroes cost EXPLICITLY
 *                   (never via a fallback multiplier) and is never blocked.
 *  - `overage`    — behaviour once the isolated allowance is exhausted.
 *
 * Immutable. Build through the named constructors so each shape is unambiguous.
 */
readonly class MeterPolicy
{
    public function __construct(
        public bool $enabled,
        public int $allowance = 0,
        public ?float $multiplier = null,
        public bool $unlimited = false,
        public OverageBehaviour $overage = OverageBehaviour::Block,
    ) {}

    /**
     * A costed, capped dimension: an isolated allowance, an explicit per-unit weight
     * for overage, and a behaviour once the allowance is spent.
     */
    public static function metered(
        int $allowance,
        float $multiplier,
        OverageBehaviour $overage = OverageBehaviour::Block,
    ): self {
        return new self(
            enabled: true,
            allowance: $allowance,
            multiplier: $multiplier,
            unlimited: false,
            overage: $overage,
        );
    }

    /**
     * An unlimited dimension: entitled, never blocked, and always zero cost. The
     * multiplier stays `null` — cost is zeroed explicitly, not via a phantom weight.
     */
    public static function unlimited(): self
    {
        return new self(
            enabled: true,
            allowance: 0,
            multiplier: null,
            unlimited: true,
            overage: OverageBehaviour::Bill,
        );
    }

    /** A disabled dimension: refused before any allowance/cost math. */
    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    /**
     * This policy with its included allowance replaced by `$allowance` — the ADR-0013
     * seam a wallet-backed resolver uses to source the exempt (free) size from the
     * meter's `included`-pool grant balance, leaving weight, overage, and entitlement
     * untouched. Every other field is preserved.
     */
    public function withAllowance(int $allowance): self
    {
        return new self(
            enabled: $this->enabled,
            allowance: $allowance,
            multiplier: $this->multiplier,
            unlimited: $this->unlimited,
            overage: $this->overage,
        );
    }

    /**
     * The weighted cost of `$units` billable (overage) units. `unlimited` returns
     * exactly `0.0`, and a `null` multiplier returns `0.0` too — never a fabricated
     * `1.0` fallback. Only a configured multiplier produces a non-zero cost.
     */
    public function costFor(int $units): float
    {
        if ($this->unlimited || $this->multiplier === null || $units <= 0) {
            return 0.0;
        }

        return $units * $this->multiplier;
    }

    /**
     * Given a claimed slice starting at `$sliceStart` (units of this meter already
     * consumed this period) and covering `$units`, how many fall within the isolated
     * allowance and are therefore exempt from cost.
     */
    public function exemptWithin(int $sliceStart, int $units): int
    {
        if ($this->unlimited) {
            return $units;
        }

        $headroom = $this->allowance - $sliceStart;

        return max(0, min($units, $headroom));
    }
}
