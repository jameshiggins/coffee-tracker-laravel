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
        Schema::create('roasters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('city');
            $table->string('website')->nullable();
            $table->string('instagram')->nullable();
            $table->text('description')->nullable();
            $table->boolean('has_shipping')->default(false);
            $table->decimal('shipping_cost', 8, 2)->nullable();
            $table->decimal('free_shipping_over', 8, 2)->nullable();
            $table->text('shipping_notes')->nullable();
            $table->boolean('has_subscription')->default(false);
            $table->text('subscription_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roasters');
    }
};
