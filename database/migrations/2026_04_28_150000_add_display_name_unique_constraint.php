<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q9: public profiles at /u/<display_name> require display_name to be a
 * unique handle. Add a UNIQUE index that permits null (some accounts —
 * particularly the SQLite test fixtures — never set one).
 *
 * SQLite supports UNIQUE constraints on nullable columns where multiple
 * NULLs are allowed; this is the desired behaviour. MySQL/Postgres do
 * the same by default. No backfill needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['display_name']);
        });
    }
};
