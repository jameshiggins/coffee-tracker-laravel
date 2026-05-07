<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the roaster's own variant-size label (e.g. "10.6oz", "300 Grams",
 * "3/4 lb") so the UI can show what the seller actually calls the bag,
 * not just our gram conversion. Real bag-weights at niche roasters often
 * convert to weird-looking gram numbers (49th Parallel's 10.6oz → 301g)
 * that confuse users. Presenting both clears it up.
 *
 * Nullable because not every scraper exposes a clean variant title
 * (WooCommerce's variation attribute combinations vary; "Default Title"
 * is useless and stays null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->string('source_size_label', 60)->nullable()->after('bag_weight_grams');
        });
    }

    public function down(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->dropColumn('source_size_label');
        });
    }
};
