<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hot read paths. On SQLite (prod) a foreignId()->constrained()
 * does NOT auto-index the FK column, and the public directory + ops/digest
 * crons filter heavily on is_active / last_import_status / last_imported_at and
 * on (roaster_id, removed_at) — all unindexed before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            // The directory query loads non-removed coffees per roaster.
            $table->index(['roaster_id', 'removed_at'], 'coffees_roaster_removed_idx');
        });

        Schema::table('roasters', function (Blueprint $table) {
            $table->index('is_active', 'roasters_is_active_idx');
            $table->index(['is_active', 'last_import_status'], 'roasters_active_status_idx');
            $table->index(['is_active', 'last_imported_at'], 'roasters_active_imported_idx');
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->dropIndex('coffees_roaster_removed_idx');
        });

        Schema::table('roasters', function (Blueprint $table) {
            $table->dropIndex('roasters_is_active_idx');
            $table->dropIndex('roasters_active_status_idx');
            $table->dropIndex('roasters_active_imported_idx');
        });
    }
};
