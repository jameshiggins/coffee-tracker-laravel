<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q14: track when a variant transitioned from out-of-stock → in-stock so
 * the daily restock-alerts cron can find the deltas without scanning
 * a separate price-history table (which we deleted in Q?-Q19).
 *
 * Updated by RoasterImporter in the same loop that writes price + stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->timestamp('in_stock_changed_at')->nullable()->after('in_stock')->index();
        });
    }

    public function down(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->dropColumn('in_stock_changed_at');
        });
    }
};
