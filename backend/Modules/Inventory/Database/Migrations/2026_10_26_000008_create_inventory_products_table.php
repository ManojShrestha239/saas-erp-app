<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignUlid('unit_of_measure_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->string('sku', 50)->unique();
            $table->string('barcode', 50)->nullable()->index();
            $table->string('name', 255)->index();
            $table->text('description')->nullable();
            $table->bigInteger('cost_price_cents')->default(0);
            $table->bigInteger('selling_price_cents')->default(0);
            $table->string('tax_category', 30)->default('standard')->index();
            $table->integer('low_stock_threshold')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index('category_id');
            $table->index('unit_of_measure_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('products');
    }
};
