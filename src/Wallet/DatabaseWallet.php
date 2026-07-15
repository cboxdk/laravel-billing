<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet;

use Cbox\Billing\Ledger\DatabaseLedger;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Enums\RemovalReason;
use Cbox\Billing\Wallet\ValueObjects\ConsumptionPlan;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\CreditRemoval;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * Durable {@see Wallet} — one row per grant LOT (see the `billing_wallet_lots`
 * migration), balances DERIVED by summing the active lots, never stored loose. It is a
 * pure storage swap for {@see InMemoryWallet}: the same {@see CreditConsumer} burn-down,
 * the same lot-attributed expiry/forfeiture semantics — only the lots now survive a
 * restart, so prepaid and promotional credit is durable.
 *
 * **Idempotent, gap-lock-safe grant.** {@see grant()} claims the lot with an
 * `insertOrIgnore` on `grant_id`'s unique index — an atomic INSERT-or-no-op. A
 * re-grant of the same id writes nothing, and because it never `SELECT … FOR UPDATE`s a
 * possibly-missing row, concurrent first-grants serialize on the unique index instead
 * of taking a shared gap lock and deadlocking (mirrors {@see DatabaseLedger},
 * ADR-0002).
 *
 * **Atomic burn-down.** {@see consume()} runs inside one transaction: it locks the org's
 * lots `FOR UPDATE`, plans the burn-down over that consistent snapshot, then decrements
 * each drawn lot with an atomic per-lot `remaining = remaining - amount`. The PAYG-sink
 * lot's draw may push its remaining below zero (the column is signed). Concurrent
 * consumes of the same org serialize on the row locks; a deadlock/serialization failure
 * surfaces as a query exception and is left to propagate — never swallowed, which would
 * commit a half-applied plan.
 *
 * **Lot-attributed removal (ADR-0006).** {@see expire()} zeroes only the unconsumed
 * remainder of each aged-out lot; {@see forfeit()} zeroes each `forfeitsOnCancel` lot's
 * positive remainder, floored at zero. Both only ever touch a POSITIVE remainder, so
 * they are idempotent (a swept lot sits at 0 and is skipped) and can never disturb a
 * `mayGoNegative` sink's debt.
 */
readonly class DatabaseWallet implements Wallet
{
    private const TABLE = 'billing_wallet_lots';

    public function __construct(
        private ConnectionInterface $db,
        private CreditConsumer $consumer = new CreditConsumer,
    ) {}

    public function grant(CreditGrant $grant): void
    {
        // insertOrIgnore is an atomic INSERT-or-no-op on grant_id's unique index: a
        // re-grant of the same id claims nothing (idempotent), and a not-yet-existing
        // row can't take a shared gap lock, so concurrent first-grants serialize on the
        // unique index instead of deadlocking. Never a SELECT … FOR UPDATE of a missing row.
        $this->db->table(self::TABLE)->insertOrIgnore([
            'grant_id' => $grant->id,
            'org' => $grant->org,
            'pool_key' => $grant->pool->key,
            'pool_spendable' => $grant->pool->spendable,
            'pool_may_go_negative' => $grant->pool->mayGoNegative,
            'pool_forfeits_on_cancel' => $grant->pool->forfeitsOnCancel,
            'pool_requires_expiry' => $grant->pool->requiresExpiry,
            'pool_reportable' => $grant->pool->reportable,
            'denomination_is_money' => $grant->denomination->isMoney,
            'denomination_code' => $grant->denomination->code,
            'remaining' => $grant->remaining,
            'expires_at' => $grant->expiresAt,
            'priority' => $grant->priority,
            'granted_at' => $grant->grantedAt,
            'kind' => $grant->kind->value,
            'cadence' => $grant->cadence->value,
        ]);
    }

    public function consume(string $org, Denomination $denomination, int $amount, array $poolOrder, int $now): ConsumptionPlan
    {
        return $this->db->transaction(function () use ($org, $denomination, $amount, $poolOrder, $now): ConsumptionPlan {
            // Lock the org's lots for the life of the burn-down so a concurrent consume
            // serializes on the same rows instead of double-spending, and plan over that
            // consistent snapshot with the SAME pure planner the in-memory wallet uses.
            $grants = $this->lockedGrantsFor($org);

            $plan = $this->consumer->plan($org, $denomination, $amount, $grants, $poolOrder, $now);

            foreach ($plan->draws as $draw) {
                // Atomic per-lot decrement. The PAYG-sink draw may push its lot's
                // remaining below zero — the column is signed, so debt is preserved.
                $this->db->table(self::TABLE)
                    ->where('grant_id', $draw->grantId)
                    ->decrement('remaining', $draw->amount);
            }

            return $plan;
        });
    }

    public function balance(string $org, Pool $pool, Denomination $denomination, int $now): int
    {
        $sum = $this->db->table(self::TABLE)
            ->where('org', $org)
            ->where('pool_key', $pool->key)
            ->where('denomination_is_money', $denomination->isMoney)
            ->where('denomination_code', $denomination->code)
            // Active = never-expiring or not yet expired at `now` (matches CreditGrant::isActive).
            ->where(static function (Builder $q) use ($now): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->sum('remaining');

        return $this->intOf($sum);
    }

    public function expire(string $org, int $now, int $lookback = Wallet::DEFAULT_LOOKBACK): RemovalReport
    {
        $floor = $now - max(0, $lookback);

        // Zero only each aged-out lot's own unconsumed remainder: expired at `now` and
        // within the look-back window. A younger or never-expiring lot in the same pool
        // is untouched, so an older lot ageing out never reduces a younger lot's balance.
        return $this->remove(
            $org,
            $now,
            RemovalReason::Expired,
            static fn (Builder $q): Builder => $q
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->where('expires_at', '>', $floor),
        );
    }

    public function forfeit(string $org, int $now): RemovalReport
    {
        // Only forfeitsOnCancel pools; floored at zero (see remove()'s remaining > 0
        // guard), so a negative pay-as-you-go pool can never offset a forfeited allotment.
        return $this->remove(
            $org,
            $now,
            RemovalReason::Forfeited,
            static fn (Builder $q): Builder => $q->where('pool_forfeits_on_cancel', true),
        );
    }

    /**
     * The org's current grant lots, locked `FOR UPDATE` so the burn-down plans and
     * applies over a snapshot no concurrent consume can move underneath it.
     *
     * @return list<CreditGrant>
     */
    private function lockedGrantsFor(string $org): array
    {
        $rows = $this->db->table(self::TABLE)
            ->where('org', $org)
            ->lockForUpdate()
            ->get();

        $grants = [];

        foreach ($rows as $row) {
            $grants[] = $this->hydrate($row);
        }

        return $grants;
    }

    /**
     * The shared core of expiry and forfeiture: within one transaction, lock the
     * matching lots that still hold a POSITIVE remainder, record each removal, and zero
     * the lot. Only a positive remainder is ever removed, so the operation is idempotent
     * (a swept lot sits at 0 and is skipped next time) and can never turn a
     * `mayGoNegative` sink's debt into a credit or deepen it.
     *
     * @param  Closure(Builder): Builder  $scope
     */
    private function remove(string $org, int $now, RemovalReason $reason, Closure $scope): RemovalReport
    {
        return $this->db->transaction(function () use ($org, $now, $reason, $scope): RemovalReport {
            $query = $this->db->table(self::TABLE)
                ->where('org', $org)
                ->where('remaining', '>', 0)
                ->lockForUpdate();

            $scope($query);

            $removals = [];

            foreach ($query->get() as $row) {
                $grant = $this->hydrate($row);

                $removals[] = new CreditRemoval(
                    grantId: $grant->id,
                    org: $grant->org,
                    pool: $grant->pool->key,
                    denomination: $grant->denomination,
                    amount: $grant->remaining,
                    reason: $reason,
                    at: $now,
                );

                $this->db->table(self::TABLE)
                    ->where('grant_id', $grant->id)
                    ->update(['remaining' => 0]);
            }

            return new RemovalReport($removals);
        });
    }

    private function hydrate(stdClass $row): CreditGrant
    {
        $code = $this->stringOf($row->denomination_code);

        return new CreditGrant(
            id: $this->stringOf($row->grant_id),
            org: $this->stringOf($row->org),
            pool: new Pool(
                key: $this->stringOf($row->pool_key),
                spendable: $this->boolOf($row->pool_spendable),
                mayGoNegative: $this->boolOf($row->pool_may_go_negative),
                forfeitsOnCancel: $this->boolOf($row->pool_forfeits_on_cancel),
                requiresExpiry: $this->boolOf($row->pool_requires_expiry),
                reportable: $this->boolOf($row->pool_reportable),
            ),
            denomination: $this->boolOf($row->denomination_is_money)
                ? Denomination::money($code)
                : Denomination::unit($code),
            remaining: $this->intOf($row->remaining),
            expiresAt: $row->expires_at === null ? null : $this->intOf($row->expires_at),
            priority: $this->intOf($row->priority),
            grantedAt: $this->intOf($row->granted_at),
            kind: GrantKind::from($this->stringOf($row->kind)),
            cadence: GrantCadence::from($this->stringOf($row->cadence)),
        );
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function boolOf(mixed $value): bool
    {
        return is_numeric($value) ? (int) $value !== 0 : $value === true;
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }
}
