<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q17: defensive moderation primitives on tastings.
 *
 * - flagged_at + flagged_by_user_id: a public reader has hit the "Report"
 *   link and admin should review. We don't auto-hide on first flag —
 *   soft-delete is admin-driven via the moderation queue.
 * - deleted_at: SoftDeletes column. Tastings hidden from public surfaces
 *   stay in the DB so we keep an audit trail (and the user can undo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tastings', function (Blueprint $table) {
            $table->timestamp('flagged_at')->nullable()->index();
            $table->foreignId('flagged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tastings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flagged_by_user_id');
            $table->dropColumn(['flagged_at', 'deleted_at']);
        });
    }
};
