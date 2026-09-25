<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the rejection snapshot a memory.
 *
 * The importer deletes and re-logs a roaster's rejections on every run, so
 * `created_at` was always "this morning" and the daily ops email could not
 * tell a drop that appeared today from one it had listed for seven weeks —
 * every row read as new, every day, and the subject line said "action needed"
 * fifty days running.
 *
 *   first_seen_at — carried forward across re-imports for the same
 *                   (coffee, bag size, reason). "New since yesterday" is
 *                   first_seen_at inside the report window; everything else
 *                   is "ongoing", listed with its age.
 *   reviewed_at   — set from the admin ("this is fine, it's a real 3 kg bag").
 *                   Also carried forward, so a reviewed row stays out of the
 *                   emails until the underlying variant changes or disappears.
 *
 * Existing rows are backdated to their created_at so nothing reads as brand
 * new the morning after this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraper_rejection_logs', function (Blueprint $table) {
            $table->timestamp('first_seen_at')->nullable()->after('context');
            $table->timestamp('reviewed_at')->nullable()->after('first_seen_at');
            $table->index('reviewed_at');
        });

        DB::table('scraper_rejection_logs')->whereNull('first_seen_at')->update([
            'first_seen_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('scraper_rejection_logs', function (Blueprint $table) {
            $table->dropIndex(['reviewed_at']);
            $table->dropColumn(['first_seen_at', 'reviewed_at']);
        });
    }
};
