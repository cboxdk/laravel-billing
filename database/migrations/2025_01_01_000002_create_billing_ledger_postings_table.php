<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger's idempotency register (ADR-0002): one row per posted natural key
 * `(org, source, reference)`. A ledger post claims a row here before writing lines;
 * a re-post of the same key finds the row already taken and is a no-op.
 *
 * This table is DELIBERATELY SEPARATE from `billing_ledger_lines` and is NOT
 * partitioned, so the `UNIQUE(org, source, reference)` index is legal and permanent.
 * The ledger-lines table, by contrast, carries no unique index and can be
 * time-partitioned later (where every UNIQUE index must include the partition key) —
 * the idempotency guarantee lives here and survives that change untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_ledger_postings', function (Blueprint $table): void {
            $table->id();
            $table->string('org');
            $table->string('source');
            $table->string('reference');
            $table->string('transaction_id')->index();
            $table->unsignedBigInteger('posted_at')->default(0);

            // The idempotency key. A concurrent re-post serializes on this unique
            // index (insertOrIgnore), so it can never double-post.
            $table->unique(['org', 'source', 'reference'], 'billing_ledger_postings_natural_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_ledger_postings');
    }
};
