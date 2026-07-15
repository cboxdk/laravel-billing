<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\CurrencyLock;

use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\Exceptions\BillingCurrencyMismatch;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Durable {@see BillingCurrencyLock}: one row per billing account in
 * `billing_account_currency_locks`, its currency stamped by the account's first
 * finalized invoice.
 *
 * {@see stampAndGuard()} opens a transaction, takes a `SELECT … FOR UPDATE` lock on
 * the account's row, and evaluates the currency against that PERSISTED row:
 *
 *  - already locked, same currency — run `$finalize`;
 *  - already locked, different currency — throw and run nothing;
 *  - not yet locked — INSERT the stamp, then run `$finalize`, so the stamp and the
 *    invoice the finalizer issues commit together or roll back together.
 *
 * Under a true concurrent first-finalize, both transactions may find no row and both
 * attempt the INSERT; the `UNIQUE(account)` index is the backstop that lets exactly
 * one win — the loser's INSERT violates the constraint and rolls its whole
 * transaction (stamp and invoice) back. So a race resolves to a single currency.
 *
 * Pair this with a database-backed invoice number sequence on the SAME connection so
 * the stamp and the number the finalizer draws share one transaction.
 */
readonly class DatabaseBillingCurrencyLock implements BillingCurrencyLock
{
    private const TABLE = 'billing_account_currency_locks';

    public function __construct(private ConnectionInterface $db) {}

    public function lockedCurrency(string $account): ?string
    {
        $row = $this->db->table(self::TABLE)
            ->where('account', $account)
            ->first();

        return $row instanceof stdClass && is_string($row->currency) ? $row->currency : null;
    }

    public function stampAndGuard(string $account, string $currency, callable $finalize): mixed
    {
        return $this->db->transaction(function () use ($account, $currency, $finalize): mixed {
            $row = $this->db->table(self::TABLE)
                ->where('account', $account)
                ->lockForUpdate()
                ->first();

            if ($row instanceof stdClass) {
                $locked = is_string($row->currency) ? $row->currency : '';

                if ($locked !== $currency) {
                    throw BillingCurrencyMismatch::forAccount($account, $locked, $currency);
                }

                return $finalize();
            }

            $this->db->table(self::TABLE)->insert([
                'account' => $account,
                'currency' => $currency,
            ]);

            return $finalize();
        });
    }
}
