<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->string('street_address')->nullable()->after('city');
            $table->string('postal_code', 16)->nullable()->after('street_address');
            $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->dropColumn(['street_address', 'postal_code', 'latitude', 'longitude']);
        });
    }
};
