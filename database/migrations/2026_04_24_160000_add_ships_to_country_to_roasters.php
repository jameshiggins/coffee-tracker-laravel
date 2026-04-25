<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            // ISO 3166-1 alpha-2 country code where the roastery is located.
            $table->string('country_code', 2)->nullable()->after('region')->index();
            // JSON array of ISO 3166-1 alpha-2 codes; e.g. ["CA","US"]; ["WORLDWIDE"] = ships everywhere.
            $table->json('ships_to')->nullable()->after('has_shipping');
        });
    }

    public function down(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'ships_to']);
        });
    }
};
