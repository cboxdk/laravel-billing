<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Support;

use Cbox\Billing\Wallet\ValueObjects\Pool;

/**
 * The default pool catalog, so a host is not forced to define its own accounts to
 * get started. Each factory returns a fresh {@see Pool}; hosts may ignore these and
 * construct pools with their own keys and behaviour.
 */
class Pools
{
    public const INCLUDED = 'included';

    public const PROMOTIONAL = 'promotional';

    public const PURCHASED = 'purchased';

    public const REGULATED = 'regulated';

    /** Recurring plan allotment: spendable, forfeited on cancel, never rolled over. */
    public static function included(): Pool
    {
        return new Pool(
            key: self::INCLUDED,
            spendable: true,
            mayGoNegative: false,
            forfeitsOnCancel: true,
            requiresExpiry: false,
            reportable: false,
        );
    }

    /** Time-boxed promotional credit: spendable, kept across cancel, must carry an expiry. */
    public static function promotional(): Pool
    {
        return new Pool(
            key: self::PROMOTIONAL,
            spendable: true,
            mayGoNegative: false,
            forfeitsOnCancel: false,
            requiresExpiry: true,
            reportable: false,
        );
    }

    /** Top-ups and accrued overage: spendable, kept across cancel, and the PAYG sink. */
    public static function purchased(): Pool
    {
        return new Pool(
            key: self::PURCHASED,
            spendable: true,
            mayGoNegative: true,
            forfeitsOnCancel: false,
            requiresExpiry: false,
            reportable: false,
        );
    }

    /** Tracked-but-unspendable regulated credit: never spent, reported, and must expire. */
    public static function regulated(): Pool
    {
        return new Pool(
            key: self::REGULATED,
            spendable: false,
            mayGoNegative: false,
            forfeitsOnCancel: false,
            requiresExpiry: true,
            reportable: true,
        );
    }

    /**
     * The default burn-down order (ADR-0013): the per-period `included` allowance
     * first, then `promotional` credit, then the PAYG `purchased` sink last so it
     * absorbs any remainder as debt (overage). Included allowance, credits, and
     * pay-as-you-go therefore mix in one deterministic order — `included → promotional
     * → purchased/PAYG-sink → overage`. The non-spendable regulated pool is
     * deliberately absent.
     *
     * @return list<Pool>
     */
    public static function defaultConsumptionOrder(): array
    {
        return [self::included(), self::promotional(), self::purchased()];
    }
}
