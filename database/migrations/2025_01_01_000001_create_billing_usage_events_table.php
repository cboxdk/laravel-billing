<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable usage event log (relational store). Append-only — one row per
 * usage event, deduplicated by the unique event id. Only created when the host
 * uses the database event log (`billing.metering.event_log = database`); event-heavy
 * deployments use a ClickHouse adapter instead and can skip this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('org');
            $table->string('meter');
            $table->string('service');
            $table->bigInteger('value');
            $table->unsignedBigInteger('occurred_at');

            $table->index(['org', 'meter', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_usage_events');
    }
};
