<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache the URL of each roaster's favicon / apple-touch-icon so the
 * directory list can show their visual brand mark next to the name.
 *
 * Filled by App\Services\Scraping\FaviconScraper during import; if the
 * scraper finds nothing we fall back to Google's S2 favicon service at
 * API response time. Admin can override via the edit form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->string('favicon_url', 500)->nullable()->after('instagram');
        });
    }

    public function down(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->dropColumn('favicon_url');
        });
    }
};
