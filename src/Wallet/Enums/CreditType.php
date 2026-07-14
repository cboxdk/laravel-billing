<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Enums;

/**
 * The category of a credit grant. The type informs the default burn-down
 * priority — promotional credits are typically spent before what the customer
 * actually paid for.
 */
enum CreditType: string
{
    case Promotional = 'promotional';
    case FreeTier = 'free_tier';
    case Granted = 'granted';
    case Prepaid = 'prepaid';

    /** Default burn-down weight — lower is spent first. */
    public function defaultPriority(): int
    {
        return match ($this) {
            self::Promotional => 10,
            self::FreeTier => 20,
            self::Granted => 30,
            self::Prepaid => 40,
        };
    }
}
