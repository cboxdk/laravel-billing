<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Enums;

/**
 * Why a grant's unconsumed remainder was removed from a wallet:
 *  - Expired   — the lot's `expiresAt` passed, so its remainder is use-it-or-lose-it.
 *  - Forfeited — the org left a subscription without landing on another, so a
 *                `forfeitsOnCancel` pool is zeroed.
 *
 * The reason travels with each removal so the ledger and regulatory reporting can
 * post the two events distinctly.
 */
enum RemovalReason: string
{
    case Expired = 'expired';
    case Forfeited = 'forfeited';
}
