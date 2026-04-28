<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('product_url');
        });
        Schema::table('coffee_variants', function (Blueprint $table) {
            // Q19: variants are already sorted ascending by bag_weight_grams in
            // the model relation; the "default" concept didn't pay rent in the UI.
            $table->dropColumn('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('purchase_link');
        });
    }
};
