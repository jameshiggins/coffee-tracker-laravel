<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coffees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roaster_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('origin');
            $table->string('process')->nullable(); // washed, natural, honey, anaerobic
            $table->string('roast_level')->nullable(); // light, medium, dark
            $table->string('varietal')->nullable();
            $table->text('tasting_notes')->nullable();
            $table->integer('bag_weight_grams');
            $table->decimal('price', 8, 2);
            // price_per_gram is computed via model accessor
            $table->boolean('in_stock')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coffees');
    }
};
