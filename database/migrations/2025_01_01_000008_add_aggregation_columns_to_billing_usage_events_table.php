<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the two optional columns the richer billable-metric aggregations read:
 * `unique_key` (counted distinctly by `UniqueCount`) and `weight` (the per-event
 * multiplier `WeightedSum` applies). Both are additive and defaulted — existing rows
 * keep a null key and a weight of 1, so `Count`/`Sum`/`Max`/`Latest` are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->string('unique_key')->nullable()->after('value');
            $table->bigInteger('weight')->default(1)->after('unique_key');
        });
    }

    public function down(): void
    {
        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->dropColumn(['unique_key', 'weight']);
        });
    }
};
