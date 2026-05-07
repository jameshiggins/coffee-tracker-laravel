<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coffee snapshot on tastings — captures the bean's state at the moment
 * the tasting was created. Coffee catalogs rotate seasonally; the same
 * coffee_id can refer to a totally different bean six months later (same
 * Shopify product slot, different lot/process/origin). The snapshot is
 * the historical truth the user actually tasted, displayed in preference
 * to the live coffee record on tasting permalinks and feeds.
 *
 * Stored as JSON: name, origin, process, roast_level, varietal,
 * tasting_notes, image_url, is_blend, roaster_name, roaster_slug,
 * snapshotted_at. ~500 bytes per row.
 *
 * Legacy tastings have null snapshot; controllers fall back to the live
 * coffee record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tastings', function (Blueprint $table) {
            $table->json('coffee_snapshot')->nullable()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('tastings', function (Blueprint $table) {
            $table->dropColumn('coffee_snapshot');
        });
    }
};
