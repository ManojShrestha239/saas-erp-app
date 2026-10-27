<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('stock_adjustment_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('quantity_before')->default(0);
            $table->integer('quantity_delta');
            $table->integer('quantity_after');
            $table->bigInteger('unit_cost_cents')->default(0);
            $table->timestamps();

            $table->index('stock_adjustment_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_adjustment_items');
    }
};
