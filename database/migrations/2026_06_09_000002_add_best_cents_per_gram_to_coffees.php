<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist each coffee's best (in-stock) price-per-gram in cents so the public
 * /api/coffees endpoint can sort and filter on price in the database instead
 * of materializing every coffee + variant and computing in PHP. Maintained by
 * RoasterImporter on every import; backfilled here for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->unsignedInteger('best_cents_per_gram')->nullable()->after('is_blend');
            $table->index('best_cents_per_gram', 'coffees_best_cpg_idx');
        });

        // Backfill: cheapest per-gram price, preferring in-stock variants and
        // falling back to all variants when none are in stock (mirrors
        // Coffee::getBestPricePerGramAttribute).
        DB::table('coffees')->select('id')->orderBy('id')->chunk(200, function ($coffees) {
            foreach ($coffees as $coffee) {
                $variants = DB::table('coffee_variants')
                    ->where('coffee_id', $coffee->id)
                    ->where('bag_weight_grams', '>', 0)
                    ->get(['price', 'bag_weight_grams', 'in_stock']);

                if ($variants->isEmpty()) {
                    continue;
                }

                $inStock = $variants->where('in_stock', 1);
                $pool = $inStock->isNotEmpty() ? $inStock : $variants;

                $best = $pool->map(fn ($v) => ($v->price / $v->bag_weight_grams) * 100)->min();

                if ($best !== null) {
                    DB::table('coffees')->where('id', $coffee->id)
                        ->update(['best_cents_per_gram' => (int) round($best)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->dropIndex('coffees_best_cpg_idx');
            $table->dropColumn('best_cents_per_gram');
        });
    }
};
