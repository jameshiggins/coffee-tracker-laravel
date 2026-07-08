<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when a roaster's CURRENT import-failure streak began (null while
 * healthy). Set on the first failed import, cleared on any success/empty
 * (the site responded, so the domain is alive). Powers the 7-day
 * auto-deactivation of roasters whose domain has gone dead, and the
 * "failing since …" column in the Needs Attention view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->timestamp('import_failing_since')->nullable()->after('last_import_error');
        });
    }

    public function down(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            $table->dropColumn('import_failing_since');
        });
    }
};
