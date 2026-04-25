<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coffee_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coffee_id')->constrained()->cascadeOnDelete();
            $table->integer('bag_weight_grams');
            $table->decimal('price', 8, 2);
            $table->boolean('in_stock')->default(true);
            $table->string('purchase_link')->nullable();
            $table->timestamps();

            $table->unique(['coffee_id', 'bag_weight_grams']);
        });

        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coffee_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 8, 2);
            $table->boolean('in_stock')->default(true);
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });

        Schema::table('coffees', function (Blueprint $table) {
            $table->dropColumn(['bag_weight_grams', 'price', 'in_stock', 'purchase_link']);
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->integer('bag_weight_grams')->default(340);
            $table->decimal('price', 8, 2)->default(0);
            $table->boolean('in_stock')->default(true);
            $table->string('purchase_link')->nullable();
        });

        Schema::dropIfExists('price_history');
        Schema::dropIfExists('coffee_variants');
    }
};
