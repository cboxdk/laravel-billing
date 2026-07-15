<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\ValueObjects\Pool;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable wallet's store: one row per credit-grant LOT. Balances are never stored
 * loose — they are DERIVED by summing the active lots' `remaining` per
 * `(org, pool_key, denomination)`, so the row set is the single source of truth and a
 * prepaid or promotional credit pack survives a restart.
 *
 * Each lot carries the full behaviour matrix of the {@see Pool}
 * it lives in (spendable / may-go-negative / forfeits-on-cancel / requires-expiry /
 * reportable) so a lot can be rehydrated into a complete grant for the burn-down,
 * expiry, and forfeiture — the durable wallet is a storage swap, not a behaviour change.
 *
 * `grant_id` carries a UNIQUE index: it is the application natural key a re-grant
 * dedupes on (`insertOrIgnore`), so a retried grant is a gap-lock-safe no-op and never
 * double-deposits. `remaining` is SIGNED — a `mayGoNegative` PAYG-sink lot may hold a
 * negative remainder (accrued overage as debt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_wallet_lots', function (Blueprint $table): void {
            $table->id();

            // The grant's application id — the idempotency natural key for grant().
            $table->string('grant_id');

            $table->string('org');

            // The pool (account) the lot lives in, plus its full behaviour matrix, so
            // the lot rehydrates into a complete grant without a pool catalog lookup.
            $table->string('pool_key');
            $table->boolean('pool_spendable');
            $table->boolean('pool_may_go_negative');
            $table->boolean('pool_forfeits_on_cancel');
            $table->boolean('pool_requires_expiry');
            $table->boolean('pool_reportable');

            // The denomination the remaining is measured in: money (currency) or a meter unit.
            $table->boolean('denomination_is_money');
            $table->string('denomination_code');

            // Signed: a PAYG-sink lot may go negative (accrued overage as debt).
            $table->bigInteger('remaining');

            // Use-it-or-lose-it instant (ms epoch); null = never expires.
            $table->unsignedBigInteger('expires_at')->nullable();

            // Lower priority burns first; grantedAt is the oldest-first tiebreaker.
            $table->integer('priority')->default(0);
            $table->bigInteger('granted_at')->default(0);

            // How a plan issued the lot (GrantKind / GrantCadence values).
            $table->string('kind');
            $table->string('cadence');

            // A re-grant of the same id serializes on this unique index (insertOrIgnore),
            // so it can never double-deposit — the gap-lock-safe idempotency backstop.
            $table->unique('grant_id', 'billing_wallet_lots_grant_id');

            // Balance derivation reads by (org, pool, denomination); the expiry sweep
            // reads by (org, expires_at).
            $table->index(['org', 'pool_key', 'denomination_is_money', 'denomination_code'], 'billing_wallet_lots_balance');
            $table->index(['org', 'expires_at'], 'billing_wallet_lots_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_wallet_lots');
    }
};
