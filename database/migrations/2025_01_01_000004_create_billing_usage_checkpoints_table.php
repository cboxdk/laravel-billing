<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-entity reconciliation checkpoint store (ADR-0003): one row per
 * `(org, meter)`. Each row records how far the entity has been reconciled and the
 * cumulative usage already posted to the ledger, so the reconciler posts a delta, not
 * a replay. Only created when the host uses the database checkpoint store
 * (`billing.reconciliation.checkpoint_store = database`).
 *
 * The `UNIQUE(org, meter)` index makes the checkpoint the row a reconcile takes its
 * `SELECT … FOR UPDATE` lock on, serializing concurrent reconciles of one entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_usage_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('org');
            $table->string('meter');

            // The reconciled ceiling and the aged-out boundary (ms epoch), both monotonic.
            $table->unsignedBigInteger('reconciled_through_ms')->default(0);
            $table->unsignedBigInteger('aged_through_ms')->default(0);

            // Cumulative usage already posted, split at the aged-out boundary.
            $table->unsignedBigInteger('meter_total')->default(0);
            $table->unsignedBigInteger('aged_total')->default(0);

            // Monotonic per-cycle discriminator for the idempotent posting key (ADR-0002).
            $table->unsignedBigInteger('sequence')->default(0);

            $table->unique(['org', 'meter'], 'billing_usage_checkpoints_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_usage_checkpoints');
    }
};
