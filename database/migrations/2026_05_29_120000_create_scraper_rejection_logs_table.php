<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trust#9: make the importer's silent variant drops observable.
 *
 * RoasterImporter::syncVariants() quietly `continue`s past variants that fail
 * a sanity gate — a non-positive price, or a cents-per-gram outside the
 * 2.5–250¢/g band (Trust#8). Those drops are correct (they keep parse bugs out
 * of the catalog) but invisible: a roaster whose whole feed misreads bag sizes
 * silently shows zero coffees with no breadcrumb. This table records each
 * rejection — which roaster/coffee, why, and the offending numbers — so the
 * weekly data-quality digest (Trust#2) and the admin surface can flag feeds
 * that need a scraper fix.
 *
 * Snapshot semantics: the importer clears a roaster's prior rows at the start
 * of each run and re-logs, so the table always reflects the LATEST import's
 * rejections rather than an unbounded daily-cron accumulation. coffee_name is
 * denormalized so a log stays readable even if the coffee is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_rejection_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roaster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coffee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coffee_name')->nullable();
            // 'price_non_positive' | 'cpg_out_of_band' (room for future gates).
            $table->string('reason', 32);
            // { price, grams, cpg, source_size_label, ... } — the offending numbers.
            $table->json('context')->nullable();
            $table->timestamps();

            // Digest groups by reason over a recent window; admin filters by roaster.
            $table->index('reason');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_rejection_logs');
    }
};
