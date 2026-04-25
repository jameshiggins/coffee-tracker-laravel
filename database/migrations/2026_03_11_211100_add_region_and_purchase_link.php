<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->string('region')->nullable()->after('city');
        });

        Schema::table('coffees', function (Blueprint $table) {
            $table->string('purchase_link')->nullable()->after('in_stock');
        });
    }

    public function down(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->dropColumn('region');
        });

        Schema::table('coffees', function (Blueprint $table) {
            $table->dropColumn('purchase_link');
        });
    }
};
