<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable audit trail of a plan-wide entitlement rollout: one row per
 * `(rollout_id, org)` recording which rollout touched which org on which plan, by which
 * path (`bulk` vs `override`), and which meter keys were written. Only needed when the
 * host binds the database rollout journal.
 *
 * The `UNIQUE(rollout_id, org)` index is what makes a rollout idempotent and crash-safe:
 * a chunk commits inside one transaction, so a partial crash writes none of its rows, and
 * a re-run upserts each row rather than duplicating it — no double audit rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_entitlement_rollouts', function (Blueprint $table): void {
            $table->id();
            $table->string('rollout_id');
            $table->string('org');
            $table->string('plan');

            // Which path applied the change to this org: 'bulk' (event-suppressed, TTL) or
            // 'override' (immediate per-org cache-bust).
            $table->string('via');

            // The meter keys written for this org, for audit — a comma-joined list.
            $table->text('meters');

            $table->unique(['rollout_id', 'org'], 'billing_entitlement_rollouts_row');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_entitlement_rollouts');
    }
};
