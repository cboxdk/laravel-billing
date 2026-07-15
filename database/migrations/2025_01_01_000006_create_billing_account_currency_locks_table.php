<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-account billing-currency lock: one row per billing account, its currency
 * stamped by the account's FIRST finalized invoice and thereafter one-way. Only used
 * when the host runs the database currency-lock store
 * (`billing.account.currency_lock_store = database`).
 *
 * The `UNIQUE(account)` index is both the one-row-per-account guarantee and the
 * backstop that serializes a concurrent first-finalize: the row a stamp takes its
 * `SELECT … FOR UPDATE` lock on, and the constraint whose violation makes the loser
 * of a simultaneous first INSERT roll its whole transaction (stamp and invoice) back,
 * so a race can never lock two currencies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_account_currency_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('account');

            // The locked ISO currency (e.g. EUR). Stamped once, never changed.
            $table->string('currency', 3);

            $table->timestamp('locked_at')->useCurrent();

            $table->unique('account', 'billing_account_currency_locks_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_account_currency_locks');
    }
};
