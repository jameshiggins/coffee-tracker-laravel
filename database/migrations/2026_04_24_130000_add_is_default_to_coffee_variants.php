<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('purchase_link');
        });
    }

    public function down(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
