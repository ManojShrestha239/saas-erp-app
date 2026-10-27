<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('stock_levels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('quantity_reserved')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
            $table->index('product_id');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_levels');
    }
};
