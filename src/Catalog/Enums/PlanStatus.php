<?php

declare(strict_types=1);

namespace Cbox\Billing\Catalog\Enums;

/**
 * Whether a catalog plan is still sold or only grandfathered:
 *  - `Offered` — in the current catalog; a valid transition source AND target.
 *  - `Legacy`  — grandfathered: still held by existing subscribers but no longer
 *                offered. A legacy plan is a valid transition **source** but never a
 *                **target** — it has no inbound edge, so once left it cannot be
 *                returned to (ADR-0010).
 */
enum PlanStatus: string
{
    case Offered = 'offered';
    case Legacy = 'legacy';
}
