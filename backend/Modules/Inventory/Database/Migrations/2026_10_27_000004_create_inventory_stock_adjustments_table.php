<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('stock_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('adjustment_number', 50)->unique();
            $table->foreignUlid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->text('reason');
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('posted')->index();
            $table->bigInteger('total_cost_impact_cents')->default(0);
            $table->timestamps();

            $table->index('warehouse_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_adjustments');
    }
};
