<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The double-entry ledger's durable store: one immutable row per posted line.
 * Append-only — rows are never updated or deleted; corrections are new reversing
 * transactions. Balances are derived by summing lines, never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_ledger_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_id')->index();
            $table->string('account')->index();
            $table->string('direction', 6);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('memo')->default('');
            $table->unsignedBigInteger('occurred_at')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['account', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_ledger_lines');
    }
};
