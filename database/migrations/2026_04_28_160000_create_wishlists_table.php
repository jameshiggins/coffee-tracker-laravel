<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q10: wishlists ("want to try") as a separate first-class table, keyed
 * by (user_id, coffee_id). Single row per user-coffee pair via the
 * UNIQUE index — adding twice is a no-op rather than a duplicate.
 *
 * Private by default; aggregate counts can be exposed publicly without
 * leaking which user wishlisted what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coffee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'coffee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};
