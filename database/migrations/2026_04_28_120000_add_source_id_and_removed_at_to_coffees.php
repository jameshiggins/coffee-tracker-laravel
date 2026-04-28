<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns Q1+Q2 need:
 *  - source_id: the platform-specific stable identifier (Shopify product id,
 *    Woo product id, etc.). Combined with roaster_id for unique identity, so
 *    re-imports match an existing coffee instead of nuke-and-recreate.
 *  - removed_at: soft-remove timestamp set by the importer when a previously-
 *    seen coffee no longer appears in a fresh fetch. Coffee row is preserved
 *    so user tastings/wishlists keep their references; UI hides removed
 *    coffees from public surfaces but shows them in MyTastings with a "no
 *    longer sold" badge (Q21).
 *
 * The (roaster_id, source_id) unique index is the canonical match key. Source
 * IDs are nullable for legacy/seeded data that predates the importer; legacy
 * rows fall back to (roaster_id, name) matching in the importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->string('source_id')->nullable()->after('roaster_id');
            $table->timestamp('removed_at')->nullable()->after('source_id')->index();
            $table->unique(['roaster_id', 'source_id'], 'coffees_roaster_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->dropUnique('coffees_roaster_source_unique');
            $table->dropColumn(['source_id', 'removed_at']);
        });
    }
};
