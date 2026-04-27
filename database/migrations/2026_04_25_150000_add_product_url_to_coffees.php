<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            // Direct link to the bean's product page on the roaster's site.
            $table->string('product_url')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->dropColumn('product_url');
        });
    }
};
