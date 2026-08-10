<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user pinned/favorite roasters, keyed by (user_id, roaster_id) —
 * the roaster-level sibling of the coffee-level wishlists table. Single
 * row per pair via the UNIQUE index; adding twice is a no-op.
 *
 * Private to the owner. The React directory sorts favorites to the top
 * of the roaster list and offers a "Pinned" filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roaster_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('roaster_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'roaster_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roaster_favorites');
    }
};
