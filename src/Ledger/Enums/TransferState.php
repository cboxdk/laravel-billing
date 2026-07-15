<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\Enums;

/**
 * The state of a two-phase transfer. `Pending` reserves value (counts toward the
 * available balance, not the posted balance); `Posted` is committed and real;
 * `Voided` is a released reservation that never affected the posted balance.
 */
enum TransferState
{
    case Pending;
    case Posted;
    case Voided;
}
