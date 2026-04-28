<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q18 currency future-proofing. Today every roaster on the directory is
 * Canadian; prices are stored in CAD with no explicit currency code.
 * If we ever expand beyond Canada the UI needs to render "USD $24" vs
 * "CAD $24" without ambiguity. Add a 3-letter ISO currency code, default
 * to CAD on existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('CAD')->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('coffee_variants', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
