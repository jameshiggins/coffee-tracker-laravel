<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-facing observability:
 *
 * - `settings`: tiny runtime key/value store so operational flags (currently
 *   `verbose_logging`) can be flipped from the admin UI instantly — env vars
 *   on Fly need a machine restart, which is exactly what a "turn on verbose
 *   logging to debug the thing happening RIGHT NOW" flow can't afford.
 * - `admin_logs`: the admin-viewable log stream. Prod's LOG_CHANNEL is
 *   stderr (Fly's log infrastructure), which the operator can't browse from
 *   the admin panel — this table is the queryable copy. Errors/warnings and
 *   audit events always land here; debug detail only while verbose logging
 *   is on. Pruned daily by logs:prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10)->index();       // debug|info|warning|error
            $table->string('event')->index();           // dotted, e.g. import.roaster.finished
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
        Schema::dropIfExists('settings');
    }
};
