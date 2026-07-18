<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

use Cbox\Billing\Catalog\ValueObjects\PlanRetirement;

/**
 * Whether a catalog plan is still sold, being retired, or only grandfathered:
 *  - `Offered`  — in the current catalog; a valid transition source AND target.
 *  - `Retiring` — sunsetting: no longer offered to new subscribers AND carrying a hard
 *                 cutoff (a {@see PlanRetirement}) by
 *                 which existing subscribers must resolve off it — migrate to a successor,
 *                 cancel, or fall to a configured default. Never a valid transition
 *                 **target**: no one may switch *onto* a plan that is being retired
 *                 (ADR-0016).
 *  - `Legacy`   — grandfathered: still held by existing subscribers but no longer
 *                 offered. A legacy plan is a valid transition **source** but never a
 *                 **target** — it has no inbound edge, so once left it cannot be
 *                 returned to (ADR-0010).
 *
 * `Retiring` differs from `Legacy` in that it is *forced*: a legacy plan may be held
 * indefinitely, whereas a retiring plan has a dated cutoff that resolves existing
 * subscribers off it at their next renewal on/after the cutoff (ADR-0016).
 */
enum PlanStatus: string
{
    case Offered = 'offered';
    case Retiring = 'retiring';
    case Legacy = 'legacy';
}
