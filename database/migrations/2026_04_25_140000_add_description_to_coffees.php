<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            // Full prose description from the roaster's product page (body_html).
            // tasting_notes stays for the short flavor list ("blueberry, citrus").
            $table->text('description')->nullable()->after('tasting_notes');
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
