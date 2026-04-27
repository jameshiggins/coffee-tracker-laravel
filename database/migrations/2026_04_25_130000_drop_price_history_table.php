<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price-history feature was removed: snapshots, daily scheduler, chart.
        // Keep the schema reversible in case we want it back later.
        Schema::dropIfExists('price_history');
    }

    public function down(): void
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coffee_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 8, 2);
            $table->boolean('in_stock')->default(true);
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });
    }
};
