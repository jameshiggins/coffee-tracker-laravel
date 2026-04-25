<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tastings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coffee_id')->constrained()->cascadeOnDelete();
            // Rating 1-10 represents half-stars (1=0.5, 10=5.0). Integer for exact comparisons.
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('notes')->nullable();
            $table->string('brew_method')->nullable(); // espresso, v60, aeropress, etc.
            $table->date('tasted_on');
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->index(['coffee_id', 'is_public']);
            $table->index(['user_id', 'tasted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tastings');
    }
};
