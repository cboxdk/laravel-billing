<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Enums;

/**
 * A billing account's standing — its trust state for gating access:
 *  - Good      — normal; the account is in good standing and access is granted.
 *  - Disputed  — a chargeback (a forced, externally-initiated reversal) is open
 *                against the account; access is gated until it is resolved.
 *  - Suspended — access is withheld for a billing reason the operator set.
 *
 * Absence of any recorded standing reads as {@see Good}: standing is a positive
 * record of trouble, not an authorization allow-list, so an untouched account keeps
 * normal access. Only an explicit flag gates it.
 */
enum AccountStandingState: string
{
    case Good = 'good';
    case Disputed = 'disputed';
    case Suspended = 'suspended';

    /** Whether an account in this standing may be granted access. */
    public function grantsAccess(): bool
    {
        return $this === self::Good;
    }
}
