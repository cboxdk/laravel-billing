<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\Enums;

/** The two sides of a double-entry posting. */
enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
