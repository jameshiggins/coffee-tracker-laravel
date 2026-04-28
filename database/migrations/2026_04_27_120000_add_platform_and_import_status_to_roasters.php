<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns the new ScraperRegistry needs:
 *  - platform: which scraper has been detected for this roaster (cached after
 *    first successful detect, so subsequent imports dispatch directly).
 *  - last_imported_at + last_import_status + last_import_error: surfaced in
 *    admin so we can tell at a glance whether a 0-bean roaster is empty,
 *    erroring, or never tried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            // 'shopify' | 'woocommerce' | 'generic'; null until first import.
            $table->string('platform', 20)->nullable()->after('website')->index();
            $table->timestamp('last_imported_at')->nullable()->after('platform');
            // 'success' | 'empty' | 'error' | 'unsupported'
            $table->string('last_import_status', 20)->nullable()->after('last_imported_at');
            $table->text('last_import_error')->nullable()->after('last_import_status');
        });
    }

    public function down(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->dropColumn(['platform', 'last_imported_at', 'last_import_status', 'last_import_error']);
        });
    }
};
