<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Enums;

/**
 * How often a plan issues a credit grant:
 *  - Once      — a one-off grant (a top-up or a single allotment).
 *  - Recurring — re-granted every billing cycle (the recurring allotment).
 */
enum GrantCadence: string
{
    case Once = 'once';
    case Recurring = 'recurring';
}
