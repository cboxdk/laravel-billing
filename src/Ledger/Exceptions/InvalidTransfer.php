<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid two-phase transfer operation: a duplicate transfer id, or
 * committing/releasing a transfer that is not pending.
 */
class InvalidTransfer extends RuntimeException
{
    public static function duplicate(string $id): self
    {
        return new self("Transfer [{$id}] already exists.");
    }

    public static function notPending(string $id): self
    {
        return new self("Transfer [{$id}] is not pending (unknown, already committed, or voided).");
    }
}
