<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\Contracts;

use Cbox\Billing\Ledger\Contracts\Ledger;
use Cbox\Billing\Wallet\CreditConsumer;
use Cbox\Billing\Wallet\ValueObjects\ConsumptionPlan;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Cbox\Billing\Wallet\ValueObjects\RemovalReport;

/**
 * An organization's wallet of credit grants, held across per-pool accounts. Balances
 * are derived per `(org, pool, denomination)` from the active grants — never stored
 * loose — and a `mayGoNegative` pool may report a negative balance.
 *
 * `consume()` runs the pool-order-aware burn-down ({@see CreditConsumer}) and applies
 * it, decrementing the drawn grants (pushing the PAYG sink negative when needed), and
 * returns the plan (which may report a shortfall for the overage policy to handle). The
 * returned {@see ConsumptionPlan} carries each draw, so a caller that owns a money
 * account chart can post the monetary drawdowns to a {@see Ledger}
 * itself; the wallet deliberately does NOT post to the ledger — it has no account chart
 * and holds unit denominations as well as money, so ledger posting is left to the layer
 * that has both.
 */
interface Wallet
{
    /**
     * Default look-back for {@see expire()}: how far before `now` the sweep reaches
     * for at-risk lots, in the same unit as a grant's `expiresAt`/`now` (milliseconds
     * for the default catalog — ~31 days). The sweep is idempotent, so running it at
     * least once per window loses nothing; the bound only keeps each sweep's work
     * finite over an unbounded history of aged-out lots.
     */
    public const int DEFAULT_LOOKBACK = 2_678_400_000;

    /**
     * Deposit a grant into its pool. A grant into a pool that `requiresExpiry` cannot
     * exist without an `expiresAt` (rejected at construction), so it can never enter here.
     */
    public function grant(CreditGrant $grant): void;

    /**
     * Consume `amount` of `denomination` for `org`, spending the spendable pools in
     * `poolOrder` and absorbing any remainder into a final `mayGoNegative` pool.
     *
     * @param  list<Pool>  $poolOrder
     */
    public function consume(string $org, Denomination $denomination, int $amount, array $poolOrder, int $now): ConsumptionPlan;

    /** Derived balance for `org` in `pool` and `denomination` (may be negative for a PAYG sink). */
    public function balance(string $org, Pool $pool, Denomination $denomination, int $now): int;

    /**
     * Sweep expired lots for `org`: zero the **unconsumed remainder** of every lot whose
     * `expiresAt` has passed at `now` and lies within `lookback` before it, leaving every
     * still-live lot in the same pool — younger or never-expiring — untouched. Because
     * lots are attributed this is exact: an older lot ageing out never reduces a younger
     * lot's balance, and a `mayGoNegative` sink's debt (a non-positive remainder) is never
     * disturbed. Idempotent — a re-run over already-swept lots removes nothing. Returns the
     * removals for ledger posting / reporting.
     */
    public function expire(string $org, int $now, int $lookback = self::DEFAULT_LOOKBACK): RemovalReport;

    /**
     * Forfeit `org`'s `forfeitsOnCancel` lots at `now`: zero each such lot's positive
     * remainder, **floored at zero** so no lot is pushed into debt. Only `forfeitsOnCancel`
     * pools are touched, so a negative pay-as-you-go pool can never offset — let alone
     * fund — a forfeitable allotment. Idempotent, and returns the removals. This is the
     * wallet half of forfeiture-on-transition; the subscription lifecycle decides *when*
     * to call it (an org left a subscription without landing on another).
     */
    public function forfeit(string $org, int $now): RemovalReport;
}
