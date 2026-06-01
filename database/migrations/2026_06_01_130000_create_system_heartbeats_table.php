<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops monitoring: a tiny key→timestamp store for the liveness signals the
 * GET /up health check reads. One row per signal, e.g.:
 *
 *   scheduler.tick — bumped every few minutes by the scheduler, so a stale
 *                    value means schedule:work has died.
 *   mail.sent      — bumped whenever an email is handed to the transport
 *                    (MessageSent), the positive "mail works" signal.
 *
 * Durable on purpose (lives on the SQLite volume) so timestamps survive
 * deploys and the health check doesn't false-alarm right after a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
